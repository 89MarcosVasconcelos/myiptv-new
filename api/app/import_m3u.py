"""Importa uma lista M3U/M3U8 (por URL ou texto colado) pros canais de TV ao vivo.

Trazido do projeto Laravel anterior (ImportPlaylistJob + M3uParser): o fluxo
"cadastrar lista -> o sistema testa os links e só guarda os que funcionam".

Diferenças pro CSV (import_channels.py), que continua existindo:
- preserva os headers que o stream exige (#EXTVLCOPT http-user-agent /
  http-referrer) — sem eles o canal falha no teste e seria descartado;
- valida cada link com o MESMO check_stream do health-check (3 estados). Só o
  que está MORTO (404/410, host que não resolve) é descartado; INCONCLUSIVO
  (403/429/timeout — comum quando quem testa é IP de datacenter) é mantido
  com is_healthy=NULL, e o navegador do usuário decide (ver ARQUITETURA.md 8.2);
- recusa links que apontam pra rede interna (anti-SSRF, ver safe_url.py);
- a gravação reaproveita _import_rows do import_channels.py (idempotente:
  rodar de novo não duplica, links novos viram mirrors do mesmo canal).
"""

import logging
import re
import threading
import unicodedata
import uuid
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

from . import job_registry
from .input_safety import clean_header_value, clean_text, clean_url, public_error
from .db import SessionLocal
from .import_channels import _run_with_deadlock_retry
from .safe_url import UnsafeUrlError, assert_public_http_url, safe_get
from .stream_validation import INCONCLUSIVO, MORTO, OK, check_stream

logger = logging.getLogger("iptv-api.import-m3u")

MAX_ENTRIES = 22_000
MAX_DOWNLOAD_BYTES = 40 * 1024 * 1024
VALIDATE_TIMEOUT_SEC = 8
VALIDATE_MAX_WORKERS = 15

_ATTR_RE = re.compile(r'([\w-]+)="([^"]*)"')


def _slug(value: str) -> str:
    normalized = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode("ascii")
    return re.sub(r"[^a-zA-Z0-9]+", "", normalized) or "canal"


def parse_m3u(text: str) -> list[dict]:
    """Devolve linhas no formato do import_channels (tvg_id, name, category,
    logo_url, language, stream_url) + referrer/user_agent. Descarta quem não
    tem URL http(s) (rtmp/rtsp/etc não passam pelo nosso player)."""
    rows: list[dict] = []
    pending: dict | None = None

    for raw in text.splitlines():
        line = raw.strip()
        if not line:
            continue
        if line.startswith("#EXTINF"):
            attrs = dict(_ATTR_RE.findall(line))
            name = line.rsplit(",", 1)[-1].strip() if "," in line else ""
            name = name or attrs.get("tvg-name", "").strip()
            # tudo que vem da lista é higienizado antes de ir pro banco (input_safety.py)
            pending = {
                "name": clean_text(name, 255),
                "tvg_id": clean_text(attrs.get("tvg-id", ""), 255),
                "category": clean_text(attrs.get("group-title", ""), 50),
                "logo_url": clean_url(attrs.get("tvg-logo", ""), 500),
                "language": clean_text(attrs.get("tvg-language", ""), 40),
                "referrer": "",
                "user_agent": "",
            }
        elif line.startswith("#EXTVLCOPT:") and pending is not None:
            key, _, value = line[len("#EXTVLCOPT:"):].partition("=")
            key = key.strip().lower()
            if key == "http-user-agent":
                pending["user_agent"] = clean_header_value(value)
            elif key in ("http-referrer", "http-referer"):
                pending["referrer"] = clean_header_value(value)
        elif line.startswith("#"):
            continue
        elif pending is not None:
            url = clean_url(line)  # só http(s) limpo; o resto (rtmp, javascript:, lixo) é descartado
            if url and pending["name"]:
                row = dict(pending)
                row["stream_url"] = url
                row["tvg_id"] = row["tvg_id"] or _slug(row["name"])
                rows.append(row)
            pending = None
    return rows


def fetch_m3u_text(url: str) -> str:
    """Baixa a lista por URL (anti-SSRF, com teto de tamanho). Decodifica como
    UTF-8 e, se falhar, como Latin-1 (várias listas BR antigas são ISO-8859-1)."""
    resp = safe_get(url, headers={"User-Agent": "Mozilla/5.0 (compatible; MPTV/1.0)"}, timeout=30, stream=True)
    try:
        if resp.status_code >= 400:
            raise ValueError(f"a origem respondeu HTTP {resp.status_code}")
        chunks, total = [], 0
        for chunk in resp.iter_content(chunk_size=64 * 1024):
            total += len(chunk)
            if total > MAX_DOWNLOAD_BYTES:
                raise ValueError(f"lista maior que {MAX_DOWNLOAD_BYTES // (1024 * 1024)}MB")
            chunks.append(chunk)
    finally:
        resp.close()
    data = b"".join(chunks)
    try:
        return data.decode("utf-8-sig")
    except UnicodeDecodeError:
        return data.decode("latin-1")


# --- job em background (mesmo padrão dos outros importadores) ---

_jobs: dict[str, dict] = {}
_jobs_lock = threading.Lock()


def _set(job_id: str, **fields) -> None:
    with _jobs_lock:
        _jobs[job_id].update(fields)


def _check_row(row: dict, validate: bool) -> str:
    """Estado de um link: OK / MORTO / INCONCLUSIVO — ou "bloqueado" (rede interna)."""
    try:
        assert_public_http_url(row["stream_url"])
    except UnsafeUrlError:
        return "bloqueado"
    if not validate:
        return INCONCLUSIVO
    headers = {}
    if row.get("user_agent"):
        headers["User-Agent"] = row["user_agent"]
    if row.get("referrer"):
        headers["Referer"] = row["referrer"]
    return check_stream(row["stream_url"], VALIDATE_TIMEOUT_SEC, headers)


ADULT_CATEGORY = "Adulto"  # mesmo valor de routers/channels.py:ADULT_CATEGORY

_ADULT_RE = re.compile(
    r"\b(xxx|adult|adulto|adultos|porn|porno|pornô|playboy|hentai|erotic|erótic\w*|nsfw|sexy)\b",
    re.IGNORECASE,
)
_PLUS18_RE = re.compile(r"18\s*\+|\+\s*18")


def is_adult_row(row: dict) -> bool:
    """Heurística simples (categoria + nome) pra pular canal adulto na importação."""
    blob = f"{row.get('category', '')} {row.get('name', '')}"
    return bool(_ADULT_RE.search(blob) or _PLUS18_RE.search(blob))


def start_import_job(url: str | None = None, text: str | None = None, validate: bool = True,
                     skip_adult: bool = False, on_done=None) -> str:
    if url:
        assert_public_http_url(url)  # falha cedo (na requisição) se a URL for interna
    elif not text or not text.strip():
        raise ValueError("informe a URL da lista ou cole o conteúdo dela")

    job_id = uuid.uuid4().hex
    with _jobs_lock:
        _jobs[job_id] = {
            "status": "running",
            "phase": "baixando" if url else "lendo",
            "total": 0,
            "processed": 0,
            "stats": None,
            "error": None,
            "started_at": datetime.now(timezone.utc).isoformat(),
        }
    cancel = job_registry.register("import-m3u", job_id, get_import_job)

    def _worker():
        db = None
        try:
            content = fetch_m3u_text(url) if url else text
            rows = parse_m3u(content)
            if not rows:
                raise ValueError("nenhum canal http(s) encontrado nessa lista (é mesmo um M3U?)")
            if len(rows) > MAX_ENTRIES:
                raise ValueError(f"lista com {len(rows)} entradas; o máximo por importação é {MAX_ENTRIES}")

            # tira duplicata exata (mesmo canal + mesmo link) dentro da própria lista
            seen, unique = set(), []
            for r in rows:
                key = (r["tvg_id"].lower(), r["stream_url"])
                if key not in seen:
                    seen.add(key)
                    unique.append(r)
            rows = unique

            # canal adulto importado vai SEMPRE pra categoria "Adulto" -- é ela que fica
            # atrás da permissão da conta + senha (routers/channels.py, adult_gate.py).
            # Sem isso, uma categoria crua ("XXX", "For Adults"...) apareceria pra todos.
            if not skip_adult:
                for r in rows:
                    if is_adult_row(r):
                        r["category"] = ADULT_CATEGORY

            adultos = 0
            if skip_adult:
                kept_rows = [r for r in rows if not is_adult_row(r)]
                adultos = len(rows) - len(kept_rows)
                rows = kept_rows
                if not rows:
                    raise ValueError("todos os canais dessa lista foram filtrados como adultos")

            _set(job_id, phase="validando" if validate else "verificando", total=len(rows), processed=0)
            states: dict[int, str] = {}
            done = 0
            with ThreadPoolExecutor(max_workers=VALIDATE_MAX_WORKERS) as pool:
                futures = {pool.submit(_check_row, r, validate): i for i, r in enumerate(rows)}
                for fut in as_completed(futures):
                    if cancel.is_set():
                        for f in futures:
                            f.cancel()
                        _set(job_id, status="cancelled", phase=None)
                        _notify(on_done, None, "cancelado")
                        return
                    try:
                        states[futures[fut]] = fut.result()
                    except Exception:
                        states[futures[fut]] = INCONCLUSIVO
                    done += 1
                    if done % 20 == 0:
                        _set(job_id, processed=done)

            kept = []
            counts = {OK: 0, INCONCLUSIVO: 0, MORTO: 0, "bloqueado": 0}
            for i, r in enumerate(rows):
                state = states.get(i, INCONCLUSIVO)
                counts[state] = counts.get(state, 0) + 1
                if state in (OK, INCONCLUSIVO):
                    r["_state"] = state
                    kept.append(r)

            _set(job_id, phase="gravando", processed=len(rows))
            stats = {"links_ok": 0, "linhas_ignoradas": [], "linhas_lixo": [], "canais_novos": 0,
                     "canais_atualizados": 0, "links_novos": 0}
            if kept:
                db = SessionLocal()
                stats = _run_with_deadlock_retry(kept, db)
            stats.update(
                total_lidos=len(rows),
                links_ok=counts[OK],
                links_inconclusivos=counts[INCONCLUSIVO],
                links_mortos=counts[MORTO],
                links_bloqueados=counts["bloqueado"],
                validado=validate,
                adultos_ignorados=adultos,
            )
            _set(job_id, status="done", phase=None, stats=stats)
            _notify(on_done, stats, None)
        except (UnsafeUrlError, ValueError) as e:
            _set(job_id, status="error", phase=None, error=public_error(e))
            _notify(on_done, None, public_error(e))
        except Exception as e:  # noqa: BLE001
            # o detalhe completo (SQL, parâmetros) vai só pro log; na tela, mensagem segura
            logger.exception("Falha ao importar M3U")
            if db is not None:
                db.rollback()
            _set(job_id, status="error", phase=None, error=public_error(e))
            _notify(on_done, None, public_error(e))
        finally:
            if db is not None:
                db.close()

    threading.Thread(target=_worker, daemon=True, name=f"import-m3u-{job_id[:8]}").start()
    return job_id


def _notify(on_done, stats, error) -> None:
    if on_done is None:
        return
    try:
        on_done(stats, error)
    except Exception:  # noqa: BLE001 - o callback nunca pode derrubar a importação
        logger.exception("callback on_done falhou")


def get_import_job(job_id: str) -> dict | None:
    with _jobs_lock:
        job = _jobs.get(job_id)
        return dict(job) if job is not None else None
