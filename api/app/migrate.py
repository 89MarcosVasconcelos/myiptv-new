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
                    # pymysql faz `query % ()`: um "%" literal (ex.: LIKE '401%') derruba a subida da API
                    conn.exec_driver_sql(statement.replace("%", "%%"))
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


def repair_vod_items(engine) -> None:
    """Conserta `vod_items` quando ela já existia com a estrutura de outro projeto.

    Achado em produção (2026-11-11): a tabela estava só com id, stream_url, is_healthy,
    consecutive_failures e last_checked_at (sem title_id, season_number, episode_number,
    episode_title e created_at), porque `CREATE TABLE IF NOT EXISTS` e o `create_all` nunca
    alteram tabela existente. Resultado: toda importação de VOD falhava com
    "Unknown column 'title_id'". Aqui as colunas que faltam são adicionadas (e o índice e a
    chave estrangeira, quando dá). Nunca derruba a subida da API.
    """
    from sqlalchemy import inspect

    try:
        insp = inspect(engine)
        if not insp.has_table("vod_items"):
            return
        cols = {c["name"] for c in insp.get_columns("vod_items")}
        with engine.connect() as conn:
            rows = conn.execute(text("SELECT COUNT(*) FROM vod_items")).scalar() or 0
        wanted = [
            # NOT NULL só dá pra exigir se a tabela estiver vazia (senão as linhas antigas ficariam órfãs)
            ("title_id", "INT NOT NULL" if rows == 0 else "INT NULL"),
            ("season_number", "INT NULL"),
            ("episode_number", "INT NULL"),
            ("episode_title", "VARCHAR(500) NULL"),
            ("created_at", "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"),
        ]
        for name, ddl in wanted:
            if name not in cols:
                logger.warning("vod_items sem a coluna %s: adicionando", name)
                with engine.begin() as conn:
                    conn.exec_driver_sql(f"ALTER TABLE vod_items ADD COLUMN {name} {ddl}")
        insp = inspect(engine)
        # na tabela antiga a coluna é NOT NULL sem DEFAULT: o INSERT do app não a informa e quebra (erro 1364)
        for c in insp.get_columns("vod_items"):
            if c["name"] == "consecutive_failures" and c.get("default") is None and not c.get("nullable", True):
                logger.warning("vod_items.consecutive_failures sem DEFAULT: ajustando")
                with engine.begin() as conn:
                    conn.exec_driver_sql("ALTER TABLE vod_items MODIFY COLUMN consecutive_failures INT NOT NULL DEFAULT 0")
        if "title_id" not in {c["name"] for c in insp.get_columns("vod_items")}:
            return
        if not any("title_id" in ix["column_names"] for ix in insp.get_indexes("vod_items")):
            with engine.begin() as conn:
                conn.exec_driver_sql("ALTER TABLE vod_items ADD INDEX idx_title (title_id)")
        has_fk = any(fk["constrained_columns"] == ["title_id"] for fk in insp.get_foreign_keys("vod_items"))
        if not has_fk and rows == 0:
            with engine.begin() as conn:
                conn.exec_driver_sql(
                    "ALTER TABLE vod_items ADD CONSTRAINT fk_vod_item_title "
                    "FOREIGN KEY (title_id) REFERENCES vod_titles(id) ON DELETE CASCADE"
                )
    except Exception:  # noqa: BLE001 - reparo é melhor-esforço
        logger.exception("Não foi possível reparar a tabela vod_items")
