"""Filtros da listagem de TV ao vivo (multi-segmento, busca por palavras).
Rodar: cd api && python -m pytest tests -q"""
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
for k, v in dict(MYSQL_HOST="x", MYSQL_USER="x", MYSQL_PASSWORD="x", MYSQL_DATABASE="x",
                 ADMIN_USERNAME="a", ADMIN_PASSWORD="b").items():
    os.environ.setdefault(k, v)

from sqlalchemy import create_engine  # noqa: E402
from sqlalchemy.orm import Session  # noqa: E402

from app.models import Channel  # noqa: E402
from app.routers.channels import CATEGORY_NONE, _apply_filters, _like_escape  # noqa: E402


def _session():
    engine = create_engine("sqlite://")
    Channel.__table__.create(engine)
    s = Session(engine)
    rows = [
        ("a", "Globo SP HD", "Notícias"),
        ("b", "Globo Rio", "Notícias"),
        ("c", "ESPN 100% Esportes", "Esportes"),
        ("d", "Canal Sem Categoria", None),
        ("e", "Snake_Case TV", "Filmes"),
    ]
    for tvg, name, cat in rows:
        s.add(Channel(tvg_id=tvg, name=name, category=cat, is_active=True))
    s.commit()
    return s


def ids(q):
    return sorted(c.tvg_id for c in q.all())


def test_varios_segmentos_sao_ou():
    s = _session()
    assert ids(_apply_filters(s.query(Channel), categories=["Notícias", "Esportes"])) == ["a", "b", "c"]


def test_sem_categoria_entra_no_filtro():
    s = _session()
    assert ids(_apply_filters(s.query(Channel), categories=["Esportes", CATEGORY_NONE])) == ["c", "d"]


def test_busca_exige_todas_as_palavras_em_qualquer_ordem():
    s = _session()
    assert ids(_apply_filters(s.query(Channel), q="sp globo")) == ["a"]
    assert ids(_apply_filters(s.query(Channel), q="globo")) == ["a", "b"]


def test_busca_escapa_curingas_like():
    s = _session()
    assert ids(_apply_filters(s.query(Channel), q="100%")) == ["c"]
    assert ids(_apply_filters(s.query(Channel), q="e_C")) == ["e"]  # "_" literal, não "qualquer char"
    assert ids(_apply_filters(s.query(Channel), q="%")) == ["c"]
    assert _like_escape("a%b_c") == "a\\%b\\_c"


def test_tvg_ids_e_category_unico_acumulam():
    s = _session()
    assert ids(_apply_filters(s.query(Channel), tvg_ids=["a", "c"], categories=["Notícias"])) == ["a"]
    assert ids(_apply_filters(s.query(Channel), category="Filmes")) == ["e"]
