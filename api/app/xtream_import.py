"""Importa FILMES, SÉRIES (com episódios) e ANIME de um painel Xtream Codes pro
catálogo VOD (as telas Filmes / Séries / Anime).

Por que existe (achado em 2026-11-11): o catálogo VOD de produção estava vazio
(0 títulos) e os filmes/séries do painel `host:porta` tinham entrado como
"canais" (import de M3U só grava canais), sem tipo, gênero nem capa. O painel
Xtream expõe tudo isso numa API própria (`player_api.php`), então importamos
direto dela:

    get_vod_categories / get_vod_streams        -> filmes
    get_series_categories / get_series          -> séries
    get_series_info&series_id=N                 -> episódios de cada série

Links gerados: `{painel}/movie/{usuario}/{senha}/{id}.{ext}` e
`{painel}/series/{usuario}/{senha}/{episodio}.{ext}`. A linha segue pelo mesmo
`_import_rows` do CSV (idempotente, sem duplicar, mirrors, retry de deadlock).

Usuário e senha NÃO são guardados em lugar nenhum além dos próprios links
(que já precisam deles pra tocar); nunca entram em mensagem de erro/log.
"""

import json
import re
import threading
import time
import uuid
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from urllib.parse import parse_qs, quote, urlencode, urlsplit

import requests

from .db import SessionLocal
from .import_m3u import is_adult_row
from .import_vod import _run_with_deadlock_retry
from .input_safety import clean_text, public_error
from .job_registry import is_cancelled, register
from .safe_url import UnsafeUrlError, assert_public_http_url, safe_get

_UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36"
_API_TIMEOUT = (10, 90)           # conexão, leitura (a lista de filmes pode ser grande)
_MAX_RESPONSE_BYTES = 150 * 1024 * 1024
MOVIE_CHUNK = 1500
SERIES_BATCH = 20
SERIES_WORKERS = 3                # poucas conexões: painel Xtream limita por conta
_EXT_RE = re.compile(r"^[a-z0-9]{2,5}$")

ADULT_GENRE = "Adulto"
ANIME_GENRE = "Anime"

# (regex na categoria -> gênero canônico do catálogo); a ordem importa (1º que bater)
_GENRE_RULES = [
    (r"\banime|animes\b|donghua", ANIME_GENRE),
    (r"document", "Documentário"),
    (r"biogra|biopic", "Biografia"),
    (r"relig|crist|gospel|evang", "Religião"),
    (r"anima|cartoon|desenho", "Animação"),
    (r"music|musical", "Musical"),
    (r"danc|dança", "Dança"),
    (r"western|faroeste", "Western"),
    (r"\bwar\b|guerra", "Guerra"),
    (r"sci[- ]?fi|science fiction|fic[cç]", "Ficção científica"),
    (r"fantas", "Fantasia"),
    (r"horror|terror", "Terror"),
    (r"thriller|suspense", "Suspense/Thriller"),
    (r"myster|mist[eé]rio", "Mistério"),
    (r"crime", "Crime"),
    (r"polic", "Policial"),
    (r"sport|espor", "Esporte"),
    (r"family|famíl|famil|kids|infantil|crian", "Família"),
    (r"romanc|romantic", "Romance"),
    (r"comed|comédia|comedia", "Comédia"),
    (r"adventure|avent", "Aventura"),
    (r"action|ação|acao", "Ação"),
    (r"drama", "Drama"),
]
_GENRE_RULES = [(re.compile(p, re.IGNORECASE), g) for p, g in _GENRE_RULES]


def genre_from_category(name: str | None) -> str | None:
    """Categoria do painel ("Netflix | drama", "Amazon | science fiction") -> gênero do catálogo.
    Sem correspondência = None (o classificador de gêneros do admin completa depois)."""
    text = name or ""
    for rx, genre in _GENRE_RULES:
        if rx.search(text):
            return genre
    return None


# --- cliente da API do painel ---

class XtreamError(Exception):
    """Erro com mensagem SEGURA pra mostrar (nunca contém usuário/senha/URL)."""


def parse_panel_url(url: str, username: str | None = None, password: str | None = None):
    """(base, usuario, senha). Aceita só o endereço do painel ("http://host:8080") ou o link
    completo da lista (".../get.php?username=U&password=P&type=m3u_plus")."""
    raw = (url or "").strip()
    if not raw:
        raise XtreamError("informe o endereço do painel")
    if "://" not in raw:
        raw = "http://" + raw
    parts = urlsplit(raw)
    if parts.scheme not in ("http", "https") or not parts.hostname:
        raise XtreamError("endereço do painel inválido")
    q = parse_qs(parts.query)
    user = (username or (q.get("username") or [""])[0]).strip()
    pw = (password or (q.get("password") or [""])[0]).strip()
    if not user or not pw:
        raise XtreamError("informe usuário e senha do painel")
    if len(user) > 200 or len(pw) > 200 or re.search(r"[\s\x00-\x1f]", user + pw):
        raise XtreamError("usuário ou senha inválidos")
    return f"{parts.scheme}://{parts.netloc}", user, pw


def _api(base: str, user: str, pw: str, action: str | None = None, **extra):
    params = {"username": user, "password": pw}
    if action:
        params["action"] = action
    params.update({k: str(v) for k, v in extra.items()})
    url = f"{base}/player_api.php?{urlencode(params)}"
    try:
        resp = safe_get(url, headers={"User-Agent": _UA}, timeout=_API_TIMEOUT, stream=True)
    except UnsafeUrlError:
        raise XtreamError("endereço do painel não permitido (rede interna)") from None
    except requests.RequestException as e:
        # a mensagem original traz a URL com usuário/senha: descarta
        raise XtreamError(f"não foi possível falar com o painel ({type(e).__name__})") from None
    try:
        if resp.status_code in (401, 403):
            raise XtreamError("o painel recusou o acesso (usuário/senha ou bloqueio de IP do servidor)")
        if resp.status_code >= 400:
            raise XtreamError(f"o painel respondeu HTTP {resp.status_code}")
        chunks, total = [], 0
        try:
            for chunk in resp.iter_content(chunk_size=262144):
                if not chunk:
                    continue
                total += len(chunk)
                if total > _MAX_RESPONSE_BYTES:
                    raise XtreamError("resposta do painel grande demais")
                chunks.append(chunk)
        except requests.RequestException as e:
            raise XtreamError(f"a conexão com o painel caiu ({type(e).__name__})") from None
        try:
            return json.loads(b"".join(chunks).decode("utf-8", errors="replace"))
        except ValueError:
            raise XtreamError("o painel não respondeu no formato esperado (não é um painel Xtream?)") from None
    finally:
        resp.close()


def account_info(base: str, user: str, pw: str) -> dict:
    """Testa a conexão: situação da conta, limite de conexões e quantas categorias existem."""
    data = _api(base, user, pw)
    info = data.get("user_info") if isinstance(data, dict) else None
    if not isinstance(info, dict) or str(info.get("auth", "1")) == "0":
        raise XtreamError("usuário ou senha recusados pelo painel")
    exp = info.get("exp_date")
    try:
        exp_txt = datetime.fromtimestamp(int(exp), timezone.utc).strftime("%d/%m/%Y") if exp else "sem validade"
    except (TypeError, ValueError, OverflowError, OSError):
        exp_txt = None
    vod_cats = _api(base, user, pw, "get_vod_categories")
    ser_cats = _api(base, user, pw, "get_series_categories")
    return {
        "status": info.get("status"),
        "expira_em": exp_txt,
        "conexoes_max": info.get("max_connections"),
        "conexoes_ativas": info.get("active_cons"),
        "categorias_filmes": len(vod_cats) if isinstance(vod_cats, list) else 0,
        "categorias_series": len(ser_cats) if isinstance(ser_cats, list) else 0,
    }


# --- montagem das linhas do import ---

def _s(v, max_len=500):
    return clean_text(str(v), max_len) if v not in (None, "") else ""


def _year(*values):
    for v in values:
        m = re.search(r"(19\d\d|20\d\d)", str(v or ""))
        if m:
            return m.group(1)
    return ""


def _rating(v):
    try:
        f = float(str(v).replace(",", "."))
    except (TypeError, ValueError):
        return ""
    return str(f) if 0 < f <= 10 else ""


def _ext(v, default="mp4"):
    e = str(v or "").strip().lower().lstrip(".")
    return e if _EXT_RE.match(e) else default


def _categories(items) -> dict[str, str]:
    out = {}
    if isinstance(items, list):
        for c in items:
            if isinstance(c, dict) and c.get("category_id") is not None:
                out[str(c["category_id"])] = _s(c.get("category_name"), 200)
    return out


def _classify(name: str, cat: str, skip_adult: bool):
    """(gênero, ignorar?). Adulto vai sempre pro gênero 'Adulto' (atrás do PIN)."""
    if is_adult_row({"category": cat, "name": name}):
        return ADULT_GENRE, skip_adult
    return genre_from_category(cat), False


def movie_rows(items, cats, base, user, pw, skip_adult=False) -> tuple[list[dict], int]:
    rows, skipped = [], 0
    for it in items:
        if not isinstance(it, dict):
            continue
        name, sid = _s(it.get("name")), it.get("stream_id")
        if not name or sid in (None, ""):
            continue
        genre, ignore = _classify(name, cats.get(str(it.get("category_id")), ""), skip_adult)
        if ignore:
            skipped += 1
            continue
        rows.append({
            "type": "movie", "title": name, "genre": genre or "",
            "poster_url": _s(it.get("stream_icon"), 500),
            "year": _year(it.get("year"), it.get("releaseDate"), it.get("releasedate")),
            "rating": _rating(it.get("rating")),
            "tmdb_id": _s(it.get("tmdb") or it.get("tmdb_id"), 12),
            "stream_url": f"{base}/movie/{quote(user, safe='')}/{quote(pw, safe='')}/{sid}.{_ext(it.get('container_extension'))}",
        })
    return rows, skipped


def _flatten_episodes(info) -> list[dict]:
    eps = info.get("episodes") if isinstance(info, dict) else None
    out = []
    if isinstance(eps, dict):
        for season, lst in eps.items():
            for e in (lst or []):
                if isinstance(e, dict):
                    e.setdefault("season", season)
                    out.append(e)
    elif isinstance(eps, list):
        for lst in eps:
            for e in (lst if isinstance(lst, list) else [lst]):
                if isinstance(e, dict):
                    out.append(e)
    return out


def series_rows(series: dict, info: dict, cats, base, user, pw, skip_adult=False) -> tuple[list[dict], bool]:
    """Linhas (1 por episódio) de UMA série + se foi ignorada por ser adulta."""
    meta = (info.get("info") if isinstance(info, dict) else None) or {}
    name = _s(series.get("name") or meta.get("name"))
    if not name:
        return [], False
    cat = cats.get(str(series.get("category_id") or meta.get("category_id")), "")
    genre, ignore = _classify(name, cat, skip_adult)
    if ignore:
        return [], True
    backdrop = series.get("backdrop_path") or meta.get("backdrop_path") or ""
    if isinstance(backdrop, list):
        backdrop = backdrop[0] if backdrop else ""
    common = {
        "type": "series", "title": name,
        "genre": genre or "",
        "description": _s(series.get("plot") or meta.get("plot"), 4000),
        "poster_url": _s(series.get("cover") or meta.get("cover"), 500),
        "backdrop_url": _s(backdrop, 600),
        "year": _year(series.get("releaseDate"), meta.get("releaseDate"), series.get("year")),
        "rating": _rating(series.get("rating") or meta.get("rating")),
        "tmdb_id": _s(series.get("tmdb") or meta.get("tmdb"), 12),
    }
    rows = []
    for e in _flatten_episodes(info):
        eid = e.get("id")
        if eid in (None, ""):
            continue
        try:
            season = int(e.get("season") or 1)
            number = int(e.get("episode_num") or 0) or None
        except (TypeError, ValueError):
            season, number = 1, None
        rows.append({
            **common,
            "season_number": str(season), "episode_number": str(number or ""),
            "episode_title": _s(e.get("title"), 500),
            "stream_url": f"{base}/series/{quote(user, safe='')}/{quote(pw, safe='')}/{eid}.{_ext(e.get('container_extension'), 'mkv')}",
        })
    return rows, False


# --- job ---

_jobs: dict[str, dict] = {}
_lock = threading.Lock()
_STAT_KEYS = ("titulos_novos", "titulos_atualizados", "itens_novos", "itens_atualizados")


def _set(job_id, **kw):
    with _lock:
        _jobs[job_id].update(kw)


def get_job(job_id: str) -> dict | None:
    with _lock:
        j = _jobs.get(job_id)
        return dict(j) if j else None


def _add_stats(job_id, stats: dict):
    with _lock:
        j = _jobs[job_id]
        for k in _STAT_KEYS:
            j[k] = j.get(k, 0) + stats.get(k, 0)


def _fetch_series_info(base, user, pw, sid):
    last = None
    for attempt in range(3):
        try:
            return _api(base, user, pw, "get_series_info", series_id=sid)
        except XtreamError as e:
            last = e
            time.sleep(1.5 * (attempt + 1))
    raise last


def start_import_job(base: str, user: str, pw: str, movies=True, series=True,
                     skip_adult=False, max_series=0) -> str:
    job_id = uuid.uuid4().hex
    with _lock:
        _jobs[job_id] = {
            "status": "running", "phase": "lendo as categorias do painel", "total": 0, "processed": 0,
            "titulos_novos": 0, "titulos_atualizados": 0, "itens_novos": 0, "itens_atualizados": 0,
            "filmes_lidos": 0, "series_lidas": 0, "series_falhas": 0, "adultos_ignorados": 0,
            "error": None, "started_at": datetime.now(timezone.utc).isoformat(),
        }
    register("Importar do painel Xtream", job_id, get_job)

    def _worker():
        db = SessionLocal()
        try:
            if movies:
                _set(job_id, phase="lendo a lista de filmes")
                cats = _categories(_api(base, user, pw, "get_vod_categories"))
                items = _api(base, user, pw, "get_vod_streams")
                items = items if isinstance(items, list) else []
                with _lock:
                    _jobs[job_id]["total"] += len(items)
                _set(job_id, phase="importando filmes", filmes_lidos=len(items))
                for i in range(0, len(items), MOVIE_CHUNK):
                    if is_cancelled(job_id):
                        _set(job_id, status="cancelled", phase=None)
                        return
                    rows, skipped = movie_rows(items[i:i + MOVIE_CHUNK], cats, base, user, pw, skip_adult)
                    if rows:
                        _add_stats(job_id, _run_with_deadlock_retry(rows, db))
                    with _lock:
                        _jobs[job_id]["adultos_ignorados"] += skipped
                        _jobs[job_id]["processed"] += len(items[i:i + MOVIE_CHUNK])
            if series:
                _set(job_id, phase="lendo a lista de séries")
                cats = _categories(_api(base, user, pw, "get_series_categories"))
                lst = _api(base, user, pw, "get_series")
                lst = [s for s in lst if isinstance(s, dict) and s.get("series_id") not in (None, "")] if isinstance(lst, list) else []
                if max_series and max_series > 0:
                    lst = lst[:max_series]
                with _lock:
                    _jobs[job_id]["total"] += len(lst)
                _set(job_id, phase="importando séries e episódios", series_lidas=len(lst))
                for i in range(0, len(lst), SERIES_BATCH):
                    if is_cancelled(job_id):
                        _set(job_id, status="cancelled", phase=None)
                        return
                    batch = lst[i:i + SERIES_BATCH]
                    rows, fails, adults = [], 0, 0
                    with ThreadPoolExecutor(max_workers=SERIES_WORKERS) as pool:
                        futs = {pool.submit(_fetch_series_info, base, user, pw, s["series_id"]): s for s in batch}
                        for fut in as_completed(futs):
                            s = futs[fut]
                            try:
                                info = fut.result()
                            except XtreamError:
                                fails += 1
                                continue
                            r, adult = series_rows(s, info, cats, base, user, pw, skip_adult)
                            adults += 1 if adult else 0
                            rows.extend(r)
                    if rows:
                        _add_stats(job_id, _run_with_deadlock_retry(rows, db))
                    with _lock:
                        _jobs[job_id]["series_falhas"] += fails
                        _jobs[job_id]["adultos_ignorados"] += adults
                        _jobs[job_id]["processed"] += len(batch)
            _set(job_id, status="done", phase=None)
        except XtreamError as e:
            db.rollback()
            _set(job_id, status="error", error=str(e))
        except Exception as e:  # noqa: BLE001
            db.rollback()
            _set(job_id, status="error", error=public_error(e))
        finally:
            db.close()

    threading.Thread(target=_worker, daemon=True, name=f"xtream-{job_id[:8]}").start()
    return job_id


# --- filmes/séries que entraram como "canais" (import de M3U do mesmo painel) ---

_VOD_URL_REGEX = r"^https?://[^/]+/(movie|series)/[^/]+/[^/]+/"


def _vod_channel_ids(db) -> list[int]:
    from sqlalchemy import func

    from .models import Channel, Stream

    matching = db.query(Stream.channel_id).filter(Stream.url.op("REGEXP")(_VOD_URL_REGEX)).distinct().subquery()
    other = db.query(Stream.channel_id).filter(~Stream.url.op("REGEXP")(_VOD_URL_REGEX)).distinct().subquery()
    rows = (
        db.query(Channel.id)
        .filter(Channel.id.in_(db.query(matching.c.channel_id)), ~Channel.id.in_(db.query(other.c.channel_id)))
        .all()
    )
    return [r[0] for r in rows]


def count_vod_channels(db) -> int:
    return len(_vod_channel_ids(db))


def delete_vod_channels(db) -> int:
    """Remove da TV ao vivo os 'canais' que na verdade são filme/série (TODOS os links deles têm
    /movie/ ou /series/ no caminho). Canal com qualquer link de TV de verdade é mantido."""
    from .models import Channel

    ids = _vod_channel_ids(db)
    removed = 0
    for i in range(0, len(ids), 1000):
        removed += db.query(Channel).filter(Channel.id.in_(ids[i:i + 1000])).delete(synchronize_session=False)
    db.commit()
    return removed
