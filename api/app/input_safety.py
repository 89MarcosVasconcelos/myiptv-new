"""Higienização de dados que vêm de listas externas (M3U, CSV, GitHub, TMDB).

O que já protege contra SQL injection: todo acesso ao banco passa pelo
SQLAlchemy com parâmetros ligados (nenhum valor de lista é concatenado em SQL
-- confira com `grep -rn "text(" api/app`: só o console SQL do admin usa SQL
cru, e ele é restrito ao admin). Esta camada cobre o resto, antes de gravar:

- caracteres de controle e NUL (quebram o banco, injetam cabeçalho HTTP com
  CR/LF e enganam a exibição);
- links que não são http(s) limpos (esquemas como javascript:/data:/file: e
  espaços/quebras de linha no meio da URL);
- tamanho acima do que a coluna aceita;
- mensagens de erro do banco que vazariam SQL e parâmetros na tela.

XSS no front: o Preact escapa todo texto interpolado e o projeto não usa
dangerouslySetInnerHTML/innerHTML; por isso aqui basta limpar o texto.
"""

import logging
import re
from urllib.parse import urlsplit

logger = logging.getLogger("iptv-api.input_safety")

# NUL, controles C0 (exceto nada: tab/CR/LF também saem de campos de uma linha),
# DEL, controles C1 e separadores unicode de linha/parágrafo.
_CTRL_RE = re.compile(r"[\x00-\x1f\x7f-\x9f  ]")
_URL_BAD_RE = re.compile(r"[\s\x00-\x1f\x7f-\x9f<>\"'`\\^{}|]")
# Mesmo limite de streams.url (migration 010)
MAX_URL_LEN = 4000


def clean_text(value, max_len: int | None = None) -> str:
    """Texto de uma linha: sem controles, espaços colapsados, cortado em max_len."""
    if value is None:
        return ""
    s = _CTRL_RE.sub(" ", str(value))
    s = re.sub(r" {2,}", " ", s).strip()
    return s[:max_len] if max_len else s


_BLOCK_CTRL_RE = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f-\x9f]")


def clean_block(value, max_len: int | None = None) -> str:
    """Texto de várias linhas (sinopse): tira NUL/controles, mantém quebras de linha."""
    if value is None:
        return ""
    s = _BLOCK_CTRL_RE.sub("", str(value).replace("\r\n", "\n").replace("\r", "\n")).strip()
    return s[:max_len] if max_len else s


def clean_url(value, max_len: int = MAX_URL_LEN) -> str:
    """Devolve a URL se for http(s) válida e limpa; senão, string vazia.
    Não 'conserta' (um link com espaço/quebra no meio é lixo ou ataque)."""
    s = (value or "").strip() if isinstance(value, str) else ""
    if not s or len(s) > max_len:
        return ""
    if _CTRL_RE.search(s) or _URL_BAD_RE.search(s):
        return ""
    try:
        parts = urlsplit(s)
    except ValueError:
        return ""
    if parts.scheme.lower() not in ("http", "https") or not parts.hostname:
        return ""
    return s


def clean_header_value(value, max_len: int = 500) -> str:
    """Valor que vai virar cabeçalho HTTP (User-Agent/Referer da lista): sem CR/LF/controles."""
    return clean_text(value, max_len)


def public_error(exc: Exception) -> str:
    """Mensagem de erro segura pra mostrar na tela. Erros nossos (ValueError e
    cia.) passam; erros do banco/bibliotecas viram texto genérico -- o detalhe
    completo (com SQL e parâmetros) fica só no log do servidor."""
    from sqlalchemy.exc import SQLAlchemyError

    if isinstance(exc, SQLAlchemyError):
        errno = None
        orig = getattr(exc, "orig", None)
        if orig is not None and getattr(orig, "args", None):
            errno = orig.args[0]
        if errno == 1406:
            return "algum valor da lista é maior do que o banco aceita (o detalhe está no log do servidor)"
        if errno in (1062,):
            return "a lista tem registros duplicados que o banco recusou (detalhe no log do servidor)"
        return "erro ao gravar no banco de dados (detalhe no log do servidor)"
    if isinstance(exc, (ValueError, LookupError, RuntimeError)):
        return str(exc)[:300]
    return "falha inesperada na importação (detalhe no log do servidor)"
