"""Rotas da página "Importar fontes" (só admin). Lógica em sources_import.py."""

import re
from typing import Optional
from urllib.parse import urlparse

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel
from sqlalchemy.orm import Session

from ..auth_admin import require_admin
from ..db import get_db
from .. import poster_fetch, sources_import
from ..models import AdminUser, ImportSource, VodTitle
from ..safe_url import UnsafeUrlError, assert_public_http_url
from ..sources_import import GitHubError

router = APIRouter(prefix="/admin")


def _preset_urls() -> set[str]:
    p = sources_import.presets()
    urls = {p["free_tv"]["url"]}
    for g in p["iptv_org"]["groups"]:
        urls.update(i["url"] for i in g["items"])
    return urls


def _check_url(url: str) -> str:
    url = (url or "").strip()
    if not re.match(r"^https?://", url, re.IGNORECASE) or len(url) > 500:
        raise HTTPException(400, "informe uma URL http(s) de até 500 caracteres")
    try:
        assert_public_http_url(url)
    except UnsafeUrlError as e:
        raise HTTPException(400, str(e)) from e
    return url


class SourceCreate(BaseModel):
    url: str
    name: Optional[str] = None
    origin: str = "custom"  # custom | github


class PresetSync(BaseModel):
    url: str
    name: str
    validate_links: bool = True
    skip_adult: bool = True


class SyncOptions(BaseModel):
    validate_links: bool = True
    skip_adult: bool = True


class RepoScan(BaseModel):
    repo: str


class CodeSearch(BaseModel):
    query: str


@router.get("/sources")
def list_sources(db: Session = Depends(get_db), _admin: AdminUser = Depends(require_admin)):
    rows = db.query(ImportSource).order_by(ImportSource.status.desc(), ImportSource.created_at.desc()).all()
    return {
        "presets": sources_import.presets(),
        "sources": [sources_import.source_row(s) for s in rows],
        "github_search_enabled": sources_import.github_search_enabled(),
        "tmdb_configured": poster_fetch.is_configured(),
    }


@router.post("/sources")
def add_source(body: SourceCreate, db: Session = Depends(get_db), _admin: AdminUser = Depends(require_admin)):
    """URL colada pelo admin entra aprovada; candidata vinda do GitHub entra PENDENTE."""
    url = _check_url(body.url)
    if body.origin not in ("custom", "github"):
        raise HTTPException(400, "origem inválida")
    if db.query(ImportSource).filter(ImportSource.url == url).first():
        raise HTTPException(409, "essa fonte já está cadastrada")
    parsed = urlparse(url)
    name = (body.name or "").strip() or f"{parsed.netloc}{parsed.path}"
    status = "pending" if body.origin == "github" else "approved"
    src = sources_import.upsert_source(db, name, url, body.origin, status)
    return sources_import.source_row(src)


@router.post("/sources/{source_id}/approve")
def approve_source(source_id: int, db: Session = Depends(get_db), _admin: AdminUser = Depends(require_admin)):
    src = db.get(ImportSource, source_id)
    if src is None:
        raise HTTPException(404, "fonte não encontrada")
    src.status = "approved"
    db.commit()
    return sources_import.source_row(src)


@router.delete("/sources/{source_id}")
def delete_source(source_id: int, db: Session = Depends(get_db), _admin: AdminUser = Depends(require_admin)):
    """Remove só o cadastro da fonte; canais já importados continuam no catálogo."""
    src = db.get(ImportSource, source_id)
    if src is None:
        raise HTTPException(404, "fonte não encontrada")
    db.delete(src)
    db.commit()
    return {"ok": True}


def _start(source_id: int, opts: SyncOptions):
    try:
        job_id = sources_import.start_sync(source_id, opts.validate_links, opts.skip_adult)
    except LookupError as e:
        raise HTTPException(404, str(e)) from e
    except PermissionError as e:
        raise HTTPException(400, str(e)) from e
    except (UnsafeUrlError, ValueError) as e:
        raise HTTPException(400, str(e)) from e
    return {"job_id": job_id, "source_id": source_id}


@router.post("/sources/{source_id}/sync")
def sync_source(source_id: int, opts: SyncOptions = SyncOptions(), _admin: AdminUser = Depends(require_admin)):
    return _start(source_id, opts)


@router.post("/sources/sync-preset")
def sync_preset(body: PresetSync, db: Session = Depends(get_db), _admin: AdminUser = Depends(require_admin)):
    """Fonte pronta (iptv-org / Free-TV): só aceita URLs do catálogo de presets."""
    if body.url not in _preset_urls():
        raise HTTPException(400, "essa URL não é uma fonte pronta")
    src = sources_import.upsert_source(db, body.name, body.url, "preset", "approved")
    return _start(src.id, SyncOptions(validate_links=body.validate_links, skip_adult=body.skip_adult))


@router.post("/sources/github/scan")
def github_scan(body: RepoScan, _admin: AdminUser = Depends(require_admin)):
    try:
        return sources_import.scan_repo(body.repo)
    except GitHubError as e:
        raise HTTPException(400, str(e)) from e


@router.post("/sources/github/search")
def github_search(body: CodeSearch, _admin: AdminUser = Depends(require_admin)):
    try:
        return sources_import.search_code(body.query)
    except GitHubError as e:
        raise HTTPException(400, str(e)) from e


# --- TMDB: escolher o resultado certo pra um título do catálogo ---

@router.get("/tmdb/search")
def tmdb_search(title: str, type: str = "movie", year: Optional[int] = None,  # noqa: A002
                _admin: AdminUser = Depends(require_admin)):
    try:
        return {"results": poster_fetch.search_candidates(title, year, type == "series")}
    except RuntimeError as e:
        raise HTTPException(400, str(e)) from e


@router.get("/tmdb/stats")
def tmdb_stats(db: Session = Depends(get_db), _admin: AdminUser = Depends(require_admin)):
    """Quantos títulos existem e quantos ainda têm dado faltando (carga complementar)."""
    return poster_fetch.metadata_stats(db)


class TmdbApply(BaseModel):
    tmdb_id: int


@router.post("/vod/{title_id}/apply-tmdb")
def tmdb_apply(title_id: int, body: TmdbApply, db: Session = Depends(get_db),
               _admin: AdminUser = Depends(require_admin)):
    """O admin escolheu o resultado certo: sobrescreve capa/fundo/sinopse/ano/gênero/nota."""
    t = db.get(VodTitle, title_id)
    if t is None:
        raise HTTPException(404, "título não encontrado")
    try:
        d = poster_fetch.fetch_details(body.tmdb_id, t.type == "series")
    except RuntimeError as e:
        raise HTTPException(400, str(e)) from e
    if d is None:
        raise HTTPException(404, "o TMDB não tem esse id")
    for field, key in (("poster_url", "poster_url"), ("backdrop_url", "backdrop_url"),
                       ("description", "overview"), ("year", "year"), ("rating", "rating")):
        if d.get(key) is not None:
            setattr(t, field, d[key])
    if d.get("genre") and t.genre != "Anime" and t.genre != "Adulto":
        t.genre = d["genre"]
    t.tmdb_id = d["tmdb_id"]
    db.commit()
    return {"ok": True, "title": {"id": t.id, "poster_url": t.poster_url, "year": t.year,
                                  "genre": t.genre, "rating": t.rating}}
