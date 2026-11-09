from datetime import datetime, timezone

from fastapi import Depends, HTTPException, Request
from sqlalchemy.orm import Session

from . import adult_gate
from .db import get_db
from .models import AccessToken


def require_valid_token(token: str, request: Request, db: Session = Depends(get_db)) -> AccessToken:
    """Valida o token do path (/p/{token}/...). Levanta 404 (não 403) para não
    confirmar a um invasor se um token existe ou não."""
    row = db.query(AccessToken).filter(AccessToken.token == token, AccessToken.is_active.is_(True)).first()
    if row is None:
        raise HTTPException(status_code=404, detail="Not found")

    row.last_used_at = datetime.now(timezone.utc)
    db.commit()
    # atributo só em memória (não é coluna): a senha do conteúdo adulto veio certa
    # nesta requisição? Só vale se a conta tiver a permissão. Ver adult_gate.py.
    row.adult_unlocked = bool(row.sees_adult_content) and adult_gate.header_ok(request, token)
    return row
