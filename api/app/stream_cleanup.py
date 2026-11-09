"""Validação dos links de canais ao vivo + limpeza dos que estão mortos.

Fluxo da tela "Importar fontes > Validar fontes":
1. o admin dispara a validação (job em background, com progresso);
2. cada link de canal é testado com o MESMO check_stream do health-check
   (OK / MORTO / INCONCLUSIVO) e o resultado é gravado em streams.is_healthy;
3. só o que está MORTO (404/410, host que não resolve) vai pra lista "com
   problema". INCONCLUSIVO (403/429/timeout, comum quando quem testa é IP de
   datacenter) fica como "desconhecido" e NÃO entra na lista — pode tocar
   perfeitamente no navegador (ver ARQUITETURA.md 8.2);
4. o admin exclui um por um ou todos de uma vez. Canal que fica sem nenhum
   link depois da exclusão é removido junto (canal sem link não toca nada).
"""

import threading
import uuid
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

from sqlalchemy import func

from .db import SessionLocal
from .job_registry import is_cancelled, register
from .models import Channel, Stream
from .stream_validation import INCONCLUSIVO, MORTO, OK, check_stream

TIMEOUT_SEC = 8
MAX_WORKERS = 40
CHUNK = 500  # grava e descarta a cada 500 links: progresso real e cancelamento rápido

_jobs: dict[str, dict] = {}
_lock = threading.Lock()


def _set(job_id: str, **kw) -> None:
    with _lock:
        _jobs[job_id].update(kw)


def get_validation_job(job_id: str) -> dict | None:
    with _lock:
        j = _jobs.get(job_id)
        return dict(j) if j else None


def _check(row: tuple) -> tuple[int, str]:
    sid, url, referrer, user_agent = row
    headers = {}
    if user_agent:
        headers["User-Agent"] = user_agent
    if referrer:
        headers["Referer"] = referrer
    try:
        return sid, check_stream(url, TIMEOUT_SEC, headers)
    except Exception:  # noqa: BLE001 - falha inesperada num link não derruba o lote
        return sid, INCONCLUSIVO


def start_validation_job() -> str:
    job_id = uuid.uuid4().hex
    with _lock:
        _jobs[job_id] = {
            "status": "running", "phase": "validando links", "total": 0, "processed": 0,
            "ok": 0, "dead": 0, "unknown": 0, "error": None,
            "started_at": datetime.now(timezone.utc).isoformat(),
        }
    register("Validar fontes", job_id, get_validation_job)

    def _worker():
        db = SessionLocal()
        try:
            total = db.query(func.count(Stream.id)).scalar() or 0
            _set(job_id, total=total)
            last_id, done = 0, 0
            ok = dead = unknown = 0
            while True:
                chunk = (
                    db.query(Stream.id, Stream.url, Stream.referrer, Stream.user_agent)
                    .filter(Stream.id > last_id).order_by(Stream.id).limit(CHUNK).all()
                )
                if not chunk:
                    break
                last_id = chunk[-1][0]
                results: dict[int, str] = {}
                cancelled = False
                with ThreadPoolExecutor(max_workers=MAX_WORKERS) as pool:
                    futs = [pool.submit(_check, tuple(r)) for r in chunk]
                    for fut in as_completed(futs):
                        sid, state = fut.result()
                        results[sid] = state
                        done += 1
                        if state == OK:
                            ok += 1
                        elif state == MORTO:
                            dead += 1
                        else:
                            unknown += 1
                        if done % 5 == 0:
                            _set(job_id, processed=done, ok=ok, dead=dead, unknown=unknown)
                        if is_cancelled(job_id):
                            cancelled = True
                            for f in futs:
                                f.cancel()
                            break
                now = datetime.now(timezone.utc).replace(tzinfo=None)
                for sid, state in results.items():
                    vals = {"last_checked_at": now}
                    if state == OK:
                        vals.update(is_healthy=True, consecutive_failures=0)
                    elif state == MORTO:
                        vals.update(is_healthy=False, consecutive_failures=Stream.consecutive_failures + 1)
                    else:
                        vals.update(is_healthy=None)
                    db.query(Stream).filter(Stream.id == sid).update(vals, synchronize_session=False)
                db.commit()
                _set(job_id, processed=done, ok=ok, dead=dead, unknown=unknown)
                if cancelled:
                    _set(job_id, status="cancelled", phase=None)
                    return
            _set(job_id, status="done", phase=None, processed=total, ok=ok, dead=dead, unknown=unknown)
        except Exception as e:  # noqa: BLE001
            db.rollback()
            _set(job_id, status="error", error=str(e))
        finally:
            db.close()

    threading.Thread(target=_worker, daemon=True, name=f"validate-{job_id[:8]}").start()
    return job_id


# --- lista "com problema" e exclusão ---

def broken_filter():
    return Stream.is_healthy.is_(False)


def list_broken(db, q: str | None, limit: int, offset: int) -> dict:
    base = db.query(Stream, Channel).join(Channel, Channel.id == Stream.channel_id).filter(broken_filter())
    if q:
        like = f"%{q.strip()}%"
        base = base.filter(Channel.name.ilike(like) | Stream.url.ilike(like))
    total = base.count()
    rows = base.order_by(Channel.name, Stream.id).offset(offset).limit(limit).all()
    now = datetime.now(timezone.utc).replace(tzinfo=None)
    return {
        "count": total,
        "items": [
            {
                "id": s.id, "channel_id": c.id, "channel_name": c.name, "url": s.url,
                "failures": s.consecutive_failures,
                "checked_age_seconds": (now - s.last_checked_at).total_seconds() if s.last_checked_at else None,
            }
            for s, c in rows
        ],
    }


def _drop_empty_channels(db, channel_ids: list[int]) -> int:
    removed = 0
    for i in range(0, len(channel_ids), 1000):
        part = channel_ids[i:i + 1000]
        keep = {r[0] for r in db.query(Stream.channel_id).filter(Stream.channel_id.in_(part)).distinct()}
        empty = [c for c in part if c not in keep]
        if empty:
            removed += db.query(Channel).filter(Channel.id.in_(empty)).delete(synchronize_session=False)
    return removed


def delete_one(db, stream_id: int) -> dict | None:
    """Exclui UM link com problema (e o canal, se era o último link dele).
    None se o link não existe ou já não está marcado como com problema."""
    s = db.query(Stream).filter(Stream.id == stream_id, broken_filter()).first()
    if s is None:
        return None
    channel_id = s.channel_id
    db.delete(s)
    db.flush()
    channels = _drop_empty_channels(db, [channel_id])
    db.commit()
    return {"links": 1, "channels": channels}


def delete_all(db) -> dict:
    """Exclui TODOS os links marcados como com problema (e canais que ficarem sem link)."""
    channel_ids = [r[0] for r in db.query(Stream.channel_id).filter(broken_filter()).distinct()]
    links = db.query(Stream).filter(broken_filter()).delete(synchronize_session=False)
    db.flush()
    channels = _drop_empty_channels(db, channel_ids)
    db.commit()
    return {"links": links, "channels": channels}
