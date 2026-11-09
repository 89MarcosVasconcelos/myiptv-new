"""Senha (PIN) do conteúdo adulto.

O canal/título "Adulto" só aparece quando as DUAS condições valem:
1. o token da conta tem `sees_adult_content` (liberado pelo admin em Usuários);
2. a requisição traz o cabeçalho `X-Adult-Pin` com a senha certa.

O navegador guarda a senha só na MEMÓRIA da aba: recarregar a página, fechar a aba
ou clicar em "Bloquear" apaga -- ou seja, a senha é pedida de novo toda vez. A
checagem é sempre no servidor (esconder só no front não adiantaria).

PIN de 4 dígitos é fácil de adivinhar, então há limite de tentativas: 5 erros
em 5 minutos travam as tentativas daquele token por 5 minutos.
"""

import hmac
import threading
import time

from fastapi import Request

from .config import ADULT_PIN

MAX_FAILS = 5
WINDOW_SEC = 300

_lock = threading.Lock()
_fails: dict[str, list[float]] = {}


def _recent(key: str, now: float) -> list[float]:
    lst = [t for t in _fails.get(key, []) if now - t < WINDOW_SEC]
    if lst:
        _fails[key] = lst
    else:
        _fails.pop(key, None)
    return lst


def is_locked(key: str) -> bool:
    with _lock:
        return len(_recent(key, time.time())) >= MAX_FAILS


def verify(pin: str | None, key: str) -> bool:
    """True se a senha confere. Erros contam pro limite; acerto zera o contador."""
    now = time.time()
    with _lock:
        if len(_recent(key, now)) >= MAX_FAILS:
            return False
        ok = bool(pin) and hmac.compare_digest(str(pin).encode(), ADULT_PIN.encode())
        if ok:
            _fails.pop(key, None)
        else:
            _fails.setdefault(key, []).append(now)
        return ok


def header_ok(request: Request, key: str) -> bool:
    pin = request.headers.get("x-adult-pin")
    return bool(pin) and verify(pin, key)
