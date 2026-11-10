"""Escolha do mirror na hora de assistir (/resolve), testando de verdade.

Antes: o servidor entregava o 1º link do ranking sem testar; se estivesse morto,
o navegador falhava (vários segundos), reportava, e o ciclo se repetia link por
link até o canal ficar sem nenhum ("nenhum mirror"). Agora o servidor testa em
paralelo os melhores candidatos e já descarta, na hora, o que está
DEFINITIVAMENTE morto (404/410, DNS que não resolve, conexão recusada). Isso:
- poupa o usuário de esperar o player falhar em cada link morto;
- grava o resultado (is_healthy=False), então o canal some da listagem sozinho;
- NÃO esconde o que é só "inconclusivo" (bloqueio de IP de datacenter, 403,
  timeout): esses continuam sendo oferecidos e quem decide é o navegador.
"""

import logging
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone

from .safe_url import UnsafeUrlError, assert_public_http_url
from .stream_validation import INCONCLUSIVO, MORTO, OK, check_stream

logger = logging.getLogger("iptv-api.resolve-probe")

PROBE_TIMEOUT_SEC = 4.0
BATCH = 4            # links testados ao mesmo tempo
MAX_BATCHES = 3      # no máximo 12 links testados por pedido (limita a espera)
FRESH_OK_MINUTES = 10  # link confirmado OK há pouco não é testado de novo


def _fresh_ok(stream, now) -> bool:
    if stream.is_healthy is not True or stream.last_checked_at is None:
        return False
    checked = stream.last_checked_at
    if checked.tzinfo is None:
        checked = checked.replace(tzinfo=timezone.utc)
    return now - checked < timedelta(minutes=FRESH_OK_MINUTES)


def _probe(stream) -> str:
    try:
        assert_public_http_url(stream.url)  # link que aponta pra rede interna nunca é testado por aqui
    except UnsafeUrlError:
        return INCONCLUSIVO
    headers = {}
    if stream.referrer:
        headers["Referer"] = stream.referrer
    if stream.user_agent:
        headers["User-Agent"] = stream.user_agent
    try:
        return check_stream(stream.url, PROBE_TIMEOUT_SEC, headers)
    except Exception:  # nunca derruba o /resolve por causa do teste
        logger.exception("falha inesperada testando %s", stream.url[:80])
        return INCONCLUSIVO


def pick_playable(db, ranked: list) -> list:
    """Recebe os mirrors já ranqueados (melhor primeiro) e devolve a lista na
    ordem final: os confirmados OK primeiro, depois os inconclusivos/ainda não
    testados, sem nenhum MORTO. Atualiza is_healthy no banco."""
    now = datetime.now(timezone.utc)
    states: dict[int, str] = {}
    pending = [s for s in ranked]
    probed = 0
    while pending and probed < BATCH * MAX_BATCHES:
        batch, pending = pending[:BATCH], pending[BATCH:]
        to_test = [s for s in batch if not _fresh_ok(s, now)]
        for s in batch:
            if _fresh_ok(s, now):
                states[s.id] = OK
        if to_test:
            with ThreadPoolExecutor(max_workers=len(to_test)) as pool:
                for s, st in zip(to_test, pool.map(_probe, to_test)):
                    states[s.id] = st
            probed += len(to_test)
        if any(states.get(s.id) in (OK, INCONCLUSIVO) for s in batch):
            break  # já achou onde tocar; o resto fica como reserva sem testar

    stamp = now.replace(tzinfo=None)
    for s in ranked:
        st = states.get(s.id)
        if st == OK:
            s.is_healthy, s.consecutive_failures, s.last_checked_at = True, 0, stamp
        elif st == MORTO:
            s.is_healthy, s.consecutive_failures, s.last_checked_at = False, (s.consecutive_failures or 0) + 1, stamp
    db.commit()

    alive = [s for s in ranked if states.get(s.id) != MORTO]
    confirmed = [s for s in alive if states.get(s.id) == OK]
    others = [s for s in alive if states.get(s.id) != OK]
    return confirmed + others
