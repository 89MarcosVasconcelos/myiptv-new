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
from .db import SessionLocal
from .import_channels import _run_with_deadlock_retry
from .safe_url import UnsafeUrlError, assert_public_http_url, safe_get
from .stream_validation import INCONCLUSIVO, MORTO, OK, check_stream

logger = logging.getLogger("iptv-api.import-m3u")

MAX_ENTRIES = 20_000
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
            pending = {
                "name": name,
                "tvg_id": attrs.get("tvg-id", "").strip(),
                "category": attrs.get("group-title", "").strip()[:50],
                "logo_url": attrs.get("tvg-logo", "").strip(),
                "language": attrs.get("tvg-language", "").strip(),
                "referrer": "",
                "user_agent": "",
            }
        elif line.startswith("#EXTVLCOPT:") and pending is not None:
            key, _, value = line[len("#EXTVLCOPT:"):].partition("=")
            key = key.strip().lower()
            if key == "http-user-agent":
                pending["user_agent"] = value.strip()
            elif key in ("http-referrer", "http-referer"):
                pending["referrer"] = value.strip()
        elif line.startswith("#"):
            continue
        elif pending is not None:
            if re.match(r"^https?://", line, re.IGNORECASE) and pending["name"]:
                row = dict(pending)
                row["stream_url"] = line
                row["tvg_id"] = row["tvg_id"] or _slug(row["name"])
                rows.append(row)
            pending = None
    return rows


def fetch_m3u_text(url: str) -> str:
    """Baixa a lista por URL (anti-SSRF, com teto de tamanho). Decodifica como
    UTF-8 e, se falhar, como Latin-1 (várias listas BR antigas são ISO-8859-1)."""
    resp = safe_get(url, headers={"User-Agent": "Mozilla/5.0 (compatible; MPIPTV/1.0)"}, timeout=30, stream=True)
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


def start_import_job(url: str | None = None, text: str | None = None, validate: bool = True) -> str:
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
            )
            _set(job_id, status="done", phase=None, stats=stats)
        except (UnsafeUrlError, ValueError) as e:
            _set(job_id, status="error", phase=None, error=str(e))
        except Exception as e:  # noqa: BLE001
            logger.exception("Falha ao importar M3U")
            if db is not None:
                db.rollback()
            _set(job_id, status="error", phase=None, error=str(e))
        finally:
            if db is not None:
                db.close()

    threading.Thread(target=_worker, daemon=True, name=f"import-m3u-{job_id[:8]}").start()
    return job_id


def get_import_job(job_id: str) -> dict | None:
    with _jobs_lock:
        job = _jobs.get(job_id)
        return dict(job) if job is not None else None
