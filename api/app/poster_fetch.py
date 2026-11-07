"""Enriquece o catálogo VOD (pôster + sinopse + ano + gênero) usando a API do
TMDB (themoviedb.org). Uma busca por título já devolve tudo: `poster_path`,
`overview` (pt-BR), data de estreia e `genre_ids`.

Precisa da chave grátis v3 em TMDB_API_KEY (.env). Sem ela o job devolve erro
claro e não faz nada.

(O fallback antigo no autocomplete não documentado da IMDb foi removido: a
IMDb não oferece API pública e os termos dela proíbem raspagem. Quando o TMDB
não tem imagem, o título fica sem capa -- dá pra escolher outro resultado à mão
em "Importar fontes > Capas e dados (TMDB)".)

Este produto usa a API do TMDB, mas não é endossado nem certificado pelo TMDB.

Nunca sobrescreve campo já preenchido — só preenche o que está vazio.
Mesmo padrão de job em background + progresso do imdb_classifier.py.
"""

import threading
import time
import uuid
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

import requests

from .config import TMDB_API_KEY
from .db import SessionLocal
from .job_registry import is_cancelled, register
from .models import VodTitle
from .title_clean import split_trailing_year

_TMDB_SEARCH = "https://api.themoviedb.org/3/search/{kind}"
# w185: o card do catálogo renderiza o pôster a 150-180px (ver Card.jsx/
# index.css) — w342 (usado antes) baixava quase o dobro do necessário, custo
# real de dado móvel sem ganho visível nesse tamanho de tela. Backdrop
# continua grande (w780) porque é usado full-width na tela de detalhe, só 1
# vez por título visto, onde a qualidade compensa o custo.
_TMDB_IMG = "https://image.tmdb.org/t/p/w185{path}"
_TMDB_BACKDROP = "https://image.tmdb.org/t/p/w780{path}"
_TMDB_DETAIL = "https://api.themoviedb.org/3/{kind}/{tmdb_id}"
_TIMEOUT = 12
_WORKERS = 20  # TMDB aguenta ~50 req/s; a resposta demora, 20 concorrentes não chega perto disso
_UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36"

# --- mapa de gênero: id do TMDB -> nosso rótulo (mesma lista/prioridade do
# imdb_index_builder). ids de filme e série; quando batem, batem no mesmo. ---
_TMDB_GENRE = {
    28: "Ação", 12: "Aventura", 16: "Animação", 35: "Comédia", 80: "Crime",
    99: "Documentário", 18: "Drama", 10751: "Família", 14: "Fantasia",
    36: "Época", 27: "Terror", 10402: "Musical", 9648: "Mistério",
    10749: "Romance", 878: "Ficção científica", 53: "Suspense/Thriller",
    10752: "Guerra", 37: "Western",
    # séries
    10759: "Ação", 10762: "Família", 10763: "Documentário", 10765: "Ficção científica",
    10766: "Drama", 10768: "Guerra",
}
# quando um título tem vários gêneros, o mais específico vence (topo desta lista)
_PRIORITY = [
    "Documentário", "Biografia", "Animação", "Musical", "Western", "Guerra",
    "Época", "Ficção científica", "Fantasia", "Terror", "Suspense/Thriller",
    "Crime", "Mistério", "Esporte", "Família", "Romance", "Comédia",
    "Aventura", "Ação", "Drama",
]


def is_configured() -> bool:
    return bool(TMDB_API_KEY)


_tl = threading.local()


def _session() -> requests.Session:
    s = getattr(_tl, "s", None)
    if s is None:
        s = requests.Session()
        s.headers["User-Agent"] = _UA
        _tl.s = s
    return s


def _pick_genre(genre_ids, is_anime: bool) -> str | None:
    ours = {_TMDB_GENRE[g] for g in (genre_ids or []) if g in _TMDB_GENRE}
    if not ours:
        return None
    for g in _PRIORITY:
        if g in ours:
            if g == "Animação" and is_anime:
                return "Anime"
            return g
    return None


def _tmdb_get(kind: str, params: dict):
    for attempt in range(4):
        try:
            r = _session().get(_TMDB_SEARCH.format(kind=kind), params=params, timeout=_TIMEOUT)
        except requests.RequestException:
            time.sleep(1 + attempt)
            continue
        if r.status_code == 429:
            time.sleep(float(r.headers.get("Retry-After", 2)) + 0.5)
            continue
        if r.status_code != 200:
            return None
        try:
            return r.json().get("results") or []
        except ValueError:
            return None
    return None


_clean_title = split_trailing_year


def _search_tmdb(title: str, year, is_series: bool):
    kind = "tv" if is_series else "movie"
    base = {"api_key": TMDB_API_KEY, "language": "pt-BR", "query": title.strip(), "include_adult": "false"}
    if year:
        base["first_air_date_year" if is_series else "primary_release_year"] = year

    results = _tmdb_get(kind, base)
    if not results and year:
        base.pop("first_air_date_year", None)
        base.pop("primary_release_year", None)
        results = _tmdb_get(kind, base)
    if not results:
        return None

    best = results[0]
    if year:
        for cand in results:
            d = cand.get("release_date") or cand.get("first_air_date") or ""
            if d[:4] == str(year):
                best = cand
                break

    return _shape(best)


def _shape(item: dict) -> dict:
    """Resultado do TMDB (busca ou detalhe) -> nosso formato."""
    date = item.get("release_date") or item.get("first_air_date") or ""
    is_anime = (item.get("original_language") == "ja") or ("JP" in (item.get("origin_country") or []))
    genre_ids = item.get("genre_ids")
    if genre_ids is None:  # endpoint de detalhe devolve "genres": [{id, name}]
        genre_ids = [g.get("id") for g in (item.get("genres") or [])]
    vote = item.get("vote_average")
    return {
        "poster_url": _TMDB_IMG.format(path=item["poster_path"]) if item.get("poster_path") else None,
        "backdrop_url": _TMDB_BACKDROP.format(path=item["backdrop_path"]) if item.get("backdrop_path") else None,
        "overview": (item.get("overview") or "").strip() or None,
        "year": int(date[:4]) if date[:4].isdigit() else None,
        "genre": _pick_genre(genre_ids, is_anime),
        "rating": round(float(vote), 1) if vote else None,
        "tmdb_id": item.get("id"),
        "title": item.get("title") or item.get("name"),
    }


def search_candidates(title: str, year, is_series: bool, limit: int = 8) -> list[dict]:
    """Vários resultados do TMDB pro admin escolher o certo (a busca automática
    pega o primeiro, que às vezes erra em título curto/ambíguo)."""
    if not is_configured():
        raise RuntimeError("TMDB_API_KEY não configurada no servidor.")
    kind = "tv" if is_series else "movie"
    params = {"api_key": TMDB_API_KEY, "language": "pt-BR", "query": title.strip(), "include_adult": "false"}
    results = _tmdb_get(kind, params)
    if results is None:
        raise RuntimeError("o TMDB não respondeu (chave inválida ou serviço fora do ar)")
    out = [_shape(r) for r in results[:limit]]
    return out


def fetch_details(tmdb_id: int, is_series: bool):
    """Detalhe de UM título do TMDB já escolhido pelo admin."""
    if not is_configured():
        raise RuntimeError("TMDB_API_KEY não configurada no servidor.")
    kind = "tv" if is_series else "movie"
    try:
        r = _session().get(_TMDB_DETAIL.format(kind=kind, tmdb_id=int(tmdb_id)),
                           params={"api_key": TMDB_API_KEY, "language": "pt-BR"}, timeout=_TIMEOUT)
    except requests.RequestException as e:
        raise RuntimeError(f"não consegui falar com o TMDB: {e}") from e
    if r.status_code == 404:
        return None
    if r.status_code != 200:
        raise RuntimeError(f"o TMDB respondeu HTTP {r.status_code}")
    return _shape(r.json())


# Tentativa anterior aqui limitava a 900 títulos a cada 20min pra "não
# sobrecarregar o TMDB" — decisão errada, revertida em 2026-09-13: o
# catálogo tem 450 MIL títulos sem dado, e nesse ritmo levaria quase uma
# semana. `_tmdb_get()` já trata 429 (rate-limit de verdade) respeitando o
# `Retry-After` que o PRÓPRIO TMDB manda — é o servidor quem deve ditar o
# ritmo, não um chute nosso. Processa tudo de uma vez com _WORKERS threads;
# só pausa de verdade quando o TMDB pede (HTTP 429).
_jobs: dict[str, dict] = {}
_lock = threading.Lock()


def _set(job_id, **kw):
    with _lock:
        _jobs[job_id].update(kw)


def start_tmdb_job() -> str:
    job_id = uuid.uuid4().hex
    with _lock:
        _jobs[job_id] = {
            "status": "running", "phase": "buscando no TMDB",
            "processed": 0, "total": 0, "matched": 0, "genres": 0, "error": None,
            "started_at": datetime.now(timezone.utc).isoformat(),
        }
    register("Buscar capas + dados (TMDB)", job_id, get_tmdb_job)

    def _worker():
        db = SessionLocal()
        try:
            if not is_configured():
                raise RuntimeError("TMDB_API_KEY não configurada no .env do servidor.")

            # tudo que ainda falta pôster OU sinopse OU gênero
            rows = list(
                db.query(
                    VodTitle.id, VodTitle.title, VodTitle.year, VodTitle.type,
                    VodTitle.poster_url.isnot(None), VodTitle.description.isnot(None),
                    VodTitle.genre.isnot(None), VodTitle.backdrop_url.isnot(None),
                    VodTitle.rating.isnot(None),
                ).filter(
                    VodTitle.poster_url.is_(None)
                    | VodTitle.description.is_(None)
                    | VodTitle.genre.is_(None)
                    | VodTitle.backdrop_url.is_(None)
                    | VodTitle.rating.is_(None)
                    | VodTitle.tmdb_id.is_(None)
                )
            )
            _set(job_id, total=len(rows))

            def task(row):
                tid, title, year, typ = row[0], row[1], row[2], row[3]
                clean, clean_year = _clean_title(title, year)
                res = _search_tmdb(clean, clean_year, typ == "series")
                if res is None and clean != title.strip():
                    res = _search_tmdb(title, year, typ == "series")
                poster = res["poster_url"] if res else None
                return row, res, poster

            matched = genres = done = 0
            cancelled = False
            _set(job_id, phase="buscando no TMDB")

            with ThreadPoolExecutor(max_workers=_WORKERS) as pool:
                futs = [pool.submit(task, r) for r in rows]
                for fut in as_completed(futs):
                    row, res, poster = fut.result()
                    tid, _t, year, _typ, has_poster, has_desc, has_genre, has_bd, has_rating = row
                    done += 1
                    vals = {}
                    if poster and not has_poster:
                        vals["poster_url"] = poster
                        matched += 1
                    if res:
                        if res["overview"] and not has_desc:
                            vals["description"] = res["overview"]
                        if res["year"] and not year:
                            vals["year"] = res["year"]
                        if res["genre"] and not has_genre:
                            vals["genre"] = res["genre"]
                            genres += 1
                        if res["backdrop_url"] and not has_bd:
                            vals["backdrop_url"] = res["backdrop_url"]
                        if res["rating"] and not has_rating:
                            vals["rating"] = res["rating"]
                        if res["tmdb_id"]:
                            vals["tmdb_id"] = res["tmdb_id"]
                    if vals:
                        db.query(VodTitle).filter(VodTitle.id == tid).update(vals, synchronize_session=False)
                    if done % 50 == 0:
                        db.commit()
                        _set(job_id, processed=done, matched=matched, genres=genres)
                    if is_cancelled(job_id):
                        cancelled = True
                        for f in futs:
                            f.cancel()
                        break

            db.commit()
            _set(job_id, processed=done, matched=matched, genres=genres)

            if cancelled:
                _set(job_id, status="cancelled", phase=f"cancelado em {done} de {len(rows)}",
                     processed=done, matched=matched, genres=genres)
            else:
                _set(job_id, status="done", phase="concluído", processed=len(rows),
                     matched=matched, genres=genres)
        except Exception as e:
            db.rollback()
            _set(job_id, status="error", error=str(e))
        finally:
            db.close()

    threading.Thread(target=_worker, daemon=True, name=f"tmdb-{job_id[:8]}").start()
    return job_id


def get_tmdb_job(job_id: str):
    with _lock:
        j = _jobs.get(job_id)
        return dict(j) if j else None
