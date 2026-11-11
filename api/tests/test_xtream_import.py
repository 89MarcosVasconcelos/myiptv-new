"""Testes das partes puras da importação Xtream. Rodar: cd api && python -m pytest tests -q"""
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
for k, v in dict(MYSQL_HOST="x", MYSQL_USER="x", MYSQL_PASSWORD="x", MYSQL_DATABASE="x",
                 ADMIN_USERNAME="a", ADMIN_PASSWORD="b").items():
    os.environ.setdefault(k, v)

import pytest  # noqa: E402

from app import xtream_import as X  # noqa: E402


def test_genero_pela_categoria():
    g = X.genre_from_category
    assert g("Netflix | drama") == "Drama"
    assert g("Amazon | science fiction") == "Ficção científica"
    assert g("Animes | legendado") == "Anime"
    assert g("Filmes | Animação") == "Animação"
    assert g("Disney+ | family") == "Família"
    assert g("Categoria esquisita") is None
    assert g(None) is None


def test_url_do_painel():
    assert X.parse_panel_url("host:8080", "u", "p") == ("http://host:8080", "u", "p")
    assert X.parse_panel_url("http://h:1/get.php?username=U&password=P%40&type=m3u_plus") == ("http://h:1", "U", "P@")
    for bad in ("", "ftp://h", "http://h:1"):
        with pytest.raises(X.XtreamError):
            X.parse_panel_url(bad)
    with pytest.raises(X.XtreamError):
        X.parse_panel_url("http://h", "u u", "p")


def test_linhas_de_filme_e_adulto():
    cats = {"1": "Netflix | drama", "3": "Adultos | XXX 18+"}
    items = [
        {"stream_id": 1, "name": "A", "category_id": "1", "container_extension": "mkv", "rating": "8.1", "year": "2021"},
        {"stream_id": 2, "name": "B", "category_id": "3"},
        {"stream_id": None, "name": "sem id"},
    ]
    rows, skipped = X.movie_rows(items, cats, "http://h:1", "u", "pwx", skip_adult=False)
    assert [r["title"] for r in rows] == ["A", "B"] and skipped == 0
    assert rows[0]["stream_url"] == "http://h:1/movie/u/pwx/1.mkv" and rows[0]["genre"] == "Drama"
    assert rows[1]["genre"] == "Adulto" and rows[1]["stream_url"].endswith("/2.mp4")
    rows, skipped = X.movie_rows(items, cats, "http://h:1", "u", "p", skip_adult=True)
    assert [r["title"] for r in rows] == ["A"] and skipped == 1


def test_serie_com_episodios():
    info = {"episodes": {"1": [{"id": "10", "episode_num": 1, "title": "x"}], "2": [{"id": "11", "episode_num": "3"}]}}
    rows, adult = X.series_rows({"series_id": 1, "name": "S", "category_id": "5"}, info, {"5": "Animes"}, "http://h", "u", "p")
    assert not adult and len(rows) == 2 and rows[0]["genre"] == "Anime"
    assert (rows[1]["season_number"], rows[1]["episode_number"]) == ("2", "3")
    assert rows[0]["stream_url"] == "http://h/series/u/p/10.mkv"
