"""Testes da higienização de dados externos (listas M3U/CSV).
Rodar: cd api && python -m pytest tests -q   (ou: python tests/test_input_safety.py)"""
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
for k, v in dict(MYSQL_HOST="x", MYSQL_USER="x", MYSQL_PASSWORD="x", MYSQL_DATABASE="x",
                 ADMIN_USERNAME="a", ADMIN_PASSWORD="b").items():
    os.environ.setdefault(k, v)

from app.import_m3u import parse_m3u  # noqa: E402
from app.input_safety import clean_block, clean_header_value, clean_text, clean_url, public_error  # noqa: E402


def test_clean_text_remove_controles_e_nul():
    assert clean_text("Canal\x00 X\r\nY\t Z") == "Canal X Y Z"
    assert clean_text("a" * 500, 255) == "a" * 255


def test_sql_injection_vira_texto_inofensivo():
    # o banco usa parâmetros ligados; aqui só garantimos que o texto não é alterado a ponto de quebrar nada
    assert clean_text("'; DROP TABLE streams; --") == "'; DROP TABLE streams; --"


def test_header_sem_crlf():
    assert "\r" not in clean_header_value("Mozilla\r\nX-Evil: 1") and "\n" not in clean_header_value("a\nb")


def test_clean_url_aceita_so_http_limpo():
    assert clean_url("https://a.com/x.m3u8?a=1&b=2") == "https://a.com/x.m3u8?a=1&b=2"
    for bad in ["javascript:alert(1)", "data:text/html,<script>", "file:///etc/passwd", "rtmp://a/b",
                "http://a.com/x y", "http://a.com/x\r\nHost: evil", "http:///semhost", "http://a.com/<script>",
                "http://a.com/\x00", "", None, "http://a.com/" + "a" * 4001]:
        assert clean_url(bad) == "", bad


def test_clean_block_mantem_quebra_de_linha():
    assert clean_block("linha1\r\nlinha2\x00") == "linha1\nlinha2"


def test_parse_m3u_hostil():
    texto = (
        "#EXTM3U\n"
        '#EXTINF:-1 tvg-id="ok1" tvg-logo="javascript:alert(1)" group-title="X",Canal Bom\n'
        "http://127.0.0.1/a.m3u8\n"
        '#EXTINF:-1 tvg-id="x2",Canal JS\n'
        "javascript:alert(1)\n"
        '#EXTINF:-1 tvg-id="x3",Canal File\n'
        "file:///etc/passwd\n"
        '#EXTINF:-1 tvg-id="x4",Canal Espaco\n'
        "http://a.com/x y\n"
        "#EXTINF:-1 tvg-id=\"x5\",Canal\x00Nul\n"
        "http://a.com/ok\n"
        '#EXTINF:-1 tvg-id="x6",Robert\'); DROP TABLE channels;--\n'
        "http://a.com/sqli\n"
    )
    rows = parse_m3u(texto)
    nomes = [r["name"] for r in rows]
    assert nomes == ["Canal Bom", "Canal Nul", "Robert'); DROP TABLE channels;--"], nomes
    assert rows[0]["logo_url"] == ""  # logo javascript: descartado
    assert all(r["stream_url"].startswith("http") for r in rows)


def test_public_error_nao_vaza_sql():
    from sqlalchemy.exc import DataError

    class Orig(Exception):
        pass

    e = DataError("INSERT INTO streams (url) VALUES (%(url)s)", {"url": "http://x"}, Orig(1406, "Data too long"))
    msg = public_error(e)
    assert "INSERT" not in msg and "http://x" not in msg and "maior" in msg


if __name__ == "__main__":
    for n, f in list(globals().items()):
        if n.startswith("test_"):
            f()
            print("ok", n)
