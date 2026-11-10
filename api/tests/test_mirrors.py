"""Testes da escolha de mirrors de canal. Rodar: cd api && python -m pytest tests -q"""
import os
import sys
from datetime import datetime, timedelta, timezone
from types import SimpleNamespace

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
for k, v in dict(MYSQL_HOST="x", MYSQL_USER="x", MYSQL_PASSWORD="x", MYSQL_DATABASE="x",
                 ADMIN_USERNAME="a", ADMIN_PASSWORD="b").items():
    os.environ.setdefault(k, v)

import requests  # noqa: E402

from app.playlist_builder import rank_mirrors  # noqa: E402
from app.stream_validation import _definitivamente_morto  # noqa: E402


def S(healthy=None, failed_min_ago=None, n=0):
    failed = datetime.now(timezone.utc) - timedelta(minutes=failed_min_ago) if failed_min_ago is not None else None
    return SimpleNamespace(is_healthy=healthy, client_failed_at=failed, client_failure_count=n,
                           consecutive_failures=0, last_checked_at=None, url="u")


def test_canal_nao_oferece_link_morto():
    ok, nulo, morto = S(True), S(None), S(False)
    assert rank_mirrors([morto, nulo, ok], exclude_dead=True) == [ok, nulo]
    assert rank_mirrors([morto], exclude_dead=True) == []


def test_vod_continua_sem_excluir_por_is_healthy():
    morto = S(False)
    assert rank_mirrors([morto]) == [morto]  # comportamento antigo (VOD)


def test_falha_recente_do_navegador_suprime_e_depois_volta():
    assert rank_mirrors([S(True, failed_min_ago=5)], exclude_dead=True) == []
    assert len(rank_mirrors([S(True, failed_min_ago=45)], exclude_dead=True)) == 1


def test_so_dns_recusado_e_url_invalida_sao_mortos():
    assert _definitivamente_morto(requests.ConnectionError("Failed to resolve 'x.invalid' ([Errno -2] Name or service not known)"))
    assert _definitivamente_morto(requests.ConnectionError("[Errno 111] Connection refused"))
    assert _definitivamente_morto(requests.exceptions.InvalidURL("bad"))
    # reset/SSL vindo do IP da VPS = cara de bloqueio, não de link morto
    assert not _definitivamente_morto(requests.ConnectionError("Connection reset by peer"))
    assert not _definitivamente_morto(requests.exceptions.SSLError("handshake failure"))


if __name__ == "__main__":
    for n, f in list(globals().items()):
        if n.startswith("test_"):
            f(); print("ok", n)
