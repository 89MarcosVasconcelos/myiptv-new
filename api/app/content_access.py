"""Quais TIPOS de conteúdo cada conta pode assistir.

Tipos: "live" (TV ao vivo), "movie" (Filmes), "series" (Séries) e "anime"
(títulos VOD com gênero "Anime", sejam filme ou série). A lista fica em
`AccessToken.content_types` (texto separado por vírgula). NULL/vazio = TUDO
liberado (tokens antigos e o token do admin não perdem acesso).

A regra é aplicada no servidor (rotas /p/{token}/...) — esconder o menu no
front é só cosmético.
"""

from typing import Optional

from sqlalchemy import and_, false, or_

from .models import AccessToken, VodTitle

LIVE, MOVIE, SERIES, ANIME = "live", "movie", "series", "anime"
ALL_KINDS = (LIVE, MOVIE, SERIES, ANIME)
ANIME_GENRE = "Anime"

# rótulos usados pela API/telas
KIND_LABELS = {LIVE: "TV ao vivo", MOVIE: "Filmes", SERIES: "Séries", ANIME: "Anime"}


def normalize(kinds) -> list[str]:
    """Lista limpa, sem repetição, na ordem canônica; ignora valor desconhecido."""
    wanted = {str(k).strip().lower() for k in (kinds or [])}
    return [k for k in ALL_KINDS if k in wanted]


def serialize(kinds) -> Optional[str]:
    return ",".join(normalize(kinds))


def allowed_kinds(access: Optional[AccessToken]) -> set[str]:
    raw = getattr(access, "content_types", None)
    if raw is None or not str(raw).strip():
        return set(ALL_KINDS)
    return set(normalize(str(raw).split(",")))


def allows_live(access: Optional[AccessToken]) -> bool:
    return LIVE in allowed_kinds(access)


def vod_clause(access: Optional[AccessToken]):
    """Condição SQLAlchemy sobre VodTitle com o que a conta pode ver, ou None
    quando não há restrição (todos os tipos de VOD liberados)."""
    kinds = allowed_kinds(access)
    if {MOVIE, SERIES, ANIME} <= kinds:
        return None
    clauses = []
    if ANIME in kinds:
        clauses.append(VodTitle.genre == ANIME_GENRE)
    plain = [k for k in (MOVIE, SERIES) if k in kinds]
    if plain:
        # NULL != 'Anime' dá NULL em SQL — por isso o OR explícito
        clauses.append(
            and_(or_(VodTitle.genre != ANIME_GENRE, VodTitle.genre.is_(None)), VodTitle.type.in_(plain))
        )
    return or_(*clauses) if clauses else false()


def title_allowed(access: Optional[AccessToken], title) -> bool:
    kinds = allowed_kinds(access)
    if title.genre == ANIME_GENRE:
        return ANIME in kinds
    return title.type in kinds
