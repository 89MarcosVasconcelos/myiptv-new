"""Página "Importar fontes" (só admin): fontes prontas, URLs salvas e descoberta no GitHub.

Princípios (uso pessoal, ver README):
- só LISTAS DE CANAIS AO VIVO entram por aqui. Filme/série continuam manuais
  (CSV / cadastro) e ganham capa/sinopse pelo TMDB;
- fontes conhecidas (iptv-org, Free-TV) e URLs que o próprio admin colou são
  importadas direto; o que vem da busca no GitHub fica PENDENTE até aprovação;
- tudo passa pelo mesmo pipeline do import_m3u (anti-SSRF, validação, anti-duplicata);
- conteúdo adulto é filtrado por padrão.
"""

import json
import re
from datetime import datetime, timezone
from urllib.parse import quote

import requests

from .config import GITHUB_TOKEN
from .db import SessionLocal
from .import_m3u import start_import_job
from .models import ImportSource

IPTV_ORG = "https://iptv-org.github.io/iptv"
FREE_TV_URL = "https://raw.githubusercontent.com/Free-TV/IPTV/master/playlist.m3u8"
FREE_TV_BASE = "https://raw.githubusercontent.com/Free-TV/IPTV/master/playlists"

# listas por país do Free-TV (playlist_<slug>.m3u8) -- só as que existem no repositório
FREE_TV_COUNTRIES = [
    ("japan", "Japão (inclui canais de anime)"), ("korea", "Coreia do Sul"), ("china", "China"),
    ("taiwan", "Taiwan"), ("india", "Índia"), ("indonesia", "Indonésia"),
    ("brazil", "Brasil"), ("portugal", "Portugal"), ("usa", "Estados Unidos"), ("canada", "Canadá"),
    ("mexico", "México"), ("argentina", "Argentina"), ("chile", "Chile"), ("peru", "Peru"),
    ("paraguay", "Paraguai"), ("venezuela", "Venezuela"), ("spain", "Espanha"), ("france", "França"),
    ("italy", "Itália"), ("germany", "Alemanha"), ("uk", "Reino Unido"), ("ireland", "Irlanda"),
    ("netherlands", "Holanda"), ("belgium", "Bélgica"), ("austria", "Áustria"), ("switzerland", "Suíça"),
    ("sweden", "Suécia"), ("norway", "Noruega"), ("denmark", "Dinamarca"), ("poland", "Polônia"),
    ("russia", "Rússia"), ("ukraine", "Ucrânia"), ("turkey", "Turquia"), ("greece", "Grécia"),
    ("israel", "Israel"), ("australia", "Austrália"),
]

# códigos ISO-3166 (país), categorias e idiomas ISO-639-3 que valem a pena expor.
# A lista completa do iptv-org é bem maior; quem quiser outra usa "URL própria".
COUNTRIES = [
    ("br", "Brasil"), ("pt", "Portugal"), ("us", "Estados Unidos"), ("ar", "Argentina"),
    ("mx", "México"), ("es", "Espanha"), ("cl", "Chile"), ("co", "Colômbia"),
    ("uy", "Uruguai"), ("py", "Paraguai"), ("pe", "Peru"), ("ao", "Angola"),
    ("mz", "Moçambique"), ("fr", "França"), ("it", "Itália"), ("de", "Alemanha"),
    ("gb", "Reino Unido"), ("jp", "Japão"),
]
CATEGORIES = [
    ("animation", "Animação"), ("kids", "Infantil"), ("movies", "Filmes"), ("series", "Séries"),
    ("news", "Notícias"), ("sports", "Esportes"), ("music", "Música"), ("documentary", "Documentário"),
    ("comedy", "Comédia"), ("classic", "Clássicos"), ("entertainment", "Variedades"),
    ("general", "Geral"), ("culture", "Cultura"), ("education", "Educação"), ("family", "Família"),
    ("science", "Ciência"), ("travel", "Viagem"), ("lifestyle", "Estilo de vida"),
]
LANGUAGES = [("por", "Português"), ("eng", "Inglês"), ("spa", "Espanhol"), ("jpn", "Japonês"),
             ("fra", "Francês"), ("ita", "Italiano"), ("deu", "Alemão")]


def presets() -> dict:
    """Catálogo de fontes prontas (o front só monta os cartões)."""
    return {
        "iptv_org": {
            "title": "iptv-org (comunitário, ~10 mil canais)",
            "note": "Lista mantida por voluntários, com bloqueio de canais retirados por pedido legal.",
            "groups": [
                {"id": "countries", "label": "Por país",
                 "items": [{"code": c, "name": n, "url": f"{IPTV_ORG}/countries/{c}.m3u"} for c, n in COUNTRIES]},
                {"id": "categories", "label": "Por categoria",
                 "items": [{"code": c, "name": n, "url": f"{IPTV_ORG}/categories/{c}.m3u"} for c, n in CATEGORIES]},
                {"id": "languages", "label": "Por idioma",
                 "items": [{"code": c, "name": n, "url": f"{IPTV_ORG}/languages/{c}.m3u"} for c, n in LANGUAGES]},
            ],
        },
        "free_tv": {
            "title": "Free-TV (só canais legais e gratuitos)",
            "note": "Lista curada com TV aberta e canais oficiais gratuitos. Importe tudo de uma vez ou só o país que quiser.",
            "url": FREE_TV_URL,
            "countries": [{"code": c, "name": n, "url": f"{FREE_TV_BASE}/playlist_{c}.m3u8"}
                          for c, n in FREE_TV_COUNTRIES],
        },
    }


# --- cadastro / sincronização -------------------------------------------------

def _now():
    return datetime.now(timezone.utc).replace(tzinfo=None)


def source_row(s: ImportSource) -> dict:
    summary = None
    if s.last_summary:
        try:
            summary = json.loads(s.last_summary)
        except ValueError:
            summary = None
    return {
        "id": s.id, "name": s.name, "url": s.url, "origin": s.origin, "status": s.status,
        "last_sync_at": s.last_sync_at.isoformat() if s.last_sync_at else None,
        "last_status": s.last_status, "last_summary": summary,
    }


def upsert_source(db, name: str, url: str, origin: str, status: str) -> ImportSource:
    src = db.query(ImportSource).filter(ImportSource.url == url).first()
    if src is None:
        src = ImportSource(name=name[:200], url=url, origin=origin, status=status)
        db.add(src)
        db.commit()
        db.refresh(src)
    return src


_SUMMARY_KEYS = ("total_lidos", "canais_novos", "canais_atualizados", "links_novos", "links_ok",
                 "links_inconclusivos", "links_mortos", "links_bloqueados", "adultos_ignorados")


def start_sync(source_id: int, validate: bool = True, skip_adult: bool = True) -> str:
    """Dispara a importação de uma fonte já cadastrada e aprovada. Devolve o job_id
    do import_m3u (a UI acompanha por /admin/channels/import-m3u/{id}/status)."""
    db = SessionLocal()
    try:
        src = db.get(ImportSource, source_id)
        if src is None:
            raise LookupError("fonte não encontrada")
        if src.status != "approved":
            raise PermissionError("aprove a fonte antes de sincronizar")
        url = src.url
        src.last_status = "running"
        db.commit()
    finally:
        db.close()

    def on_done(stats, error):
        d = SessionLocal()
        try:
            s = d.get(ImportSource, source_id)
            if s is None:
                return
            s.last_sync_at = _now()
            if error:
                s.last_status = "error"
                s.last_summary = json.dumps({"erro": error}, ensure_ascii=False)
            else:
                s.last_status = "done"
                s.last_summary = json.dumps({k: stats.get(k, 0) for k in _SUMMARY_KEYS}, ensure_ascii=False)
            d.commit()
        finally:
            d.close()

    try:
        return start_import_job(url=url, validate=validate, skip_adult=skip_adult, on_done=on_done)
    except Exception:
        d = SessionLocal()
        try:
            s = d.get(ImportSource, source_id)
            if s is not None:
                s.last_status = "error"
                d.commit()
        finally:
            d.close()
        raise


# --- GitHub ---------------------------------------------------------------------

_GH_API = "https://api.github.com"
_REPO_RE = re.compile(r"^(?:https?://github\.com/)?([\w.-]+)/([\w.-]+?)(?:\.git)?(?:/.*)?$")
_LIST_EXT = (".m3u", ".m3u8")
MAX_CANDIDATES = 200
MAX_LIST_BYTES = 40 * 1024 * 1024


class GitHubError(Exception):
    pass


def github_search_enabled() -> bool:
    return bool(GITHUB_TOKEN)


def _gh(path: str, params: dict | None = None):
    headers = {"Accept": "application/vnd.github+json", "User-Agent": "MPTV-admin",
               "X-GitHub-Api-Version": "2022-11-28"}
    if GITHUB_TOKEN:
        headers["Authorization"] = f"Bearer {GITHUB_TOKEN}"
    try:
        r = requests.get(_GH_API + path, params=params, headers=headers, timeout=20)
    except requests.RequestException as e:
        raise GitHubError(f"não consegui falar com o GitHub: {e}") from e
    if r.status_code in (403, 429) and r.headers.get("X-RateLimit-Remaining") == "0":
        raise GitHubError("limite de consultas do GitHub atingido; tente de novo em alguns minutos")
    if r.status_code == 401:
        raise GitHubError("GITHUB_TOKEN inválido ou expirado")
    if r.status_code == 404:
        raise GitHubError("repositório não encontrado (ou privado)")
    if r.status_code == 422:
        raise GitHubError("busca recusada pelo GitHub (consulta inválida)")
    if r.status_code >= 400:
        raise GitHubError(f"GitHub respondeu HTTP {r.status_code}")
    return r.json()


def _raw(owner: str, repo: str, ref: str, path: str) -> str:
    return f"https://raw.githubusercontent.com/{owner}/{repo}/{ref}/{quote(path)}"


def scan_repo(repo: str) -> dict:
    """Lista os .m3u/.m3u8 de um repositório (branch padrão). Não baixa nada."""
    m = _REPO_RE.match(repo.strip())
    if not m:
        raise GitHubError("informe o repositório como dono/nome (ex.: Free-TV/IPTV)")
    owner, name = m.group(1), m.group(2)
    info = _gh(f"/repos/{owner}/{name}")
    branch = info.get("default_branch") or "master"
    tree = _gh(f"/repos/{owner}/{name}/git/trees/{quote(branch)}", {"recursive": "1"})
    out = []
    for node in tree.get("tree", []):
        if node.get("type") != "blob" or not node.get("path", "").lower().endswith(_LIST_EXT):
            continue
        if (node.get("size") or 0) > MAX_LIST_BYTES:
            continue
        out.append({
            "name": f"{owner}/{name} · {node['path']}", "path": node["path"], "size": node.get("size"),
            "url": _raw(owner, name, branch, node["path"]),
        })
        if len(out) >= MAX_CANDIDATES:
            break
    return {"repo": f"{owner}/{name}", "branch": branch, "stars": info.get("stargazers_count"),
            "truncated": bool(tree.get("truncated")) or len(out) >= MAX_CANDIDATES, "candidates": out}


def search_code(query: str) -> dict:
    """Busca arquivos .m3u/.m3u8 por código no GitHub (precisa de GITHUB_TOKEN)."""
    if not GITHUB_TOKEN:
        raise GitHubError("a busca por código precisa do GITHUB_TOKEN configurado no servidor")
    q = (query or "").strip()
    if len(q) < 2:
        raise GitHubError("digite o que procurar (ex.: brasil, filmes, canais)")
    data = _gh("/search/code", {"q": f"{q} extension:m3u", "per_page": 30})
    out = []
    for it in data.get("items", []):
        repo = (it.get("repository") or {}).get("full_name") or ""
        if "/" not in repo:
            continue
        owner, name = repo.split("/", 1)
        out.append({
            "name": f"{repo} · {it.get('path', '')}", "path": it.get("path", ""), "size": None,
            "url": _raw(owner, name, "HEAD", it.get("path", "")), "repo": repo,
        })
    return {"query": q, "total": data.get("total_count", len(out)), "candidates": out}
