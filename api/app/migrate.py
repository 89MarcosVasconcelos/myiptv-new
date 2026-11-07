"""Aplica as migrações SQL (mysql/migrations/*.sql) sozinho na subida da API.

Por que existe: no projeto original essas migrações rodavam via
docker-entrypoint-initdb.d do container mysql — só na PRIMEIRA vez que o
volume era criado. No Coolify o banco pode ser recriado/apontado pra outro
lugar e bind-mount de arquivo do repo é frágil, então a própria API garante o
schema: aplica em ordem o que ainda não foi aplicado e registra em
`schema_migrations`.

Tolerante de propósito: se a migração tenta criar uma coluna/índice/tabela que
já existe (banco criado antes por create_all, ou por outra via), o erro
"já existe" é ignorado e a migração é marcada como aplicada.
"""

import logging
import os
import re
import time
from pathlib import Path

from sqlalchemy import text
from sqlalchemy.exc import OperationalError, ProgrammingError

logger = logging.getLogger("iptv-api.migrate")

# 1050 tabela já existe · 1060 coluna duplicada · 1061 índice duplicado
_ALREADY_EXISTS_ERRNOS = {1050, 1060, 1061}
# 1146 tabela inexistente: a migração depende de uma tabela que só o create_all
# cria — adia (não registra) e tenta de novo depois do create_all
_MISSING_TABLE_ERRNO = 1146


def _migrations_dir() -> Path:
    env = os.environ.get("MIGRATIONS_DIR")
    if env:
        return Path(env)
    here = Path(__file__).resolve().parent
    # imagem Docker: /app/app/migrate.py -> /app/migrations ; repo: api/app -> ../../mysql/migrations
    for candidate in (here.parent / "migrations", here.parent.parent / "mysql" / "migrations"):
        if candidate.is_dir():
            return candidate
    return here.parent / "migrations"


def _split_statements(sql: str) -> list[str]:
    # tira comentários de linha ("-- ...") antes de separar por ";"
    lines = [ln for ln in sql.splitlines() if not ln.strip().startswith("--")]
    cleaned = "\n".join(lines)
    return [s.strip() for s in re.split(r";\s*(?:\n|$)", cleaned) if s.strip()]


def wait_for_db(engine, attempts: int = 40, delay: float = 3.0) -> None:
    for i in range(1, attempts + 1):
        try:
            with engine.connect() as conn:
                conn.execute(text("SELECT 1"))
            return
        except OperationalError:
            logger.warning("MySQL ainda não respondeu (tentativa %d/%d)...", i, attempts)
            time.sleep(delay)
    raise RuntimeError("Não foi possível conectar ao MySQL a tempo.")


def run_migrations(engine, final: bool = True) -> None:
    """final=False: migrações que dependem de tabela inexistente são adiadas."""
    directory = _migrations_dir()
    if not directory.is_dir():
        logger.warning("Pasta de migrações %s não existe — pulando (create_all cobre o resto).", directory)
        return

    with engine.begin() as conn:
        conn.execute(
            text(
                "CREATE TABLE IF NOT EXISTS schema_migrations ("
                " filename VARCHAR(255) PRIMARY KEY,"
                " applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"
                ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            )
        )
        applied = {row[0] for row in conn.execute(text("SELECT filename FROM schema_migrations"))}

    for path in sorted(directory.glob("*.sql")):
        if path.name in applied:
            continue
        logger.info("Aplicando migração %s", path.name)
        deferred = False
        for statement in _split_statements(path.read_text(encoding="utf-8")):
            try:
                with engine.begin() as conn:
                    conn.exec_driver_sql(statement)
            except (OperationalError, ProgrammingError) as e:
                errno = e.orig.args[0] if e.orig is not None and e.orig.args else None
                if errno in _ALREADY_EXISTS_ERRNOS:
                    logger.info("  (já existia, ignorado: %s)", str(e.orig)[:120])
                    continue
                if errno == _MISSING_TABLE_ERRNO and not final:
                    logger.info("  (tabela ainda não existe, adiado até o create_all: %s)", str(e.orig)[:120])
                    deferred = True
                    continue
                raise
        if deferred:
            continue
        with engine.begin() as conn:
            conn.execute(text("INSERT IGNORE INTO schema_migrations (filename) VALUES (:f)"), {"f": path.name})
