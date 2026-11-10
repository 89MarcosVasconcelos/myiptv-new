"""Proxy de MANIFESTO HLS. Alguns provedores (Pluto TV) servem o .m3u8 com CORS
restrito (`access-control-allow-origin: http://pluto.tv`), então o hls.js no
navegador não consegue buscar o manifesto direto.

Modo padrão: só o manifesto passa por aqui (poucos KB por sessão) e os
segmentos https vão direto do CDN. Segmentos/chaves http:// (mixed content na
página https) já voltam pelo /media do servidor automaticamente.
Modo `full=1`: TUDO (segmentos, chaves, init) volta pelo servidor -- usado
quando o provedor https também não libera CORS nos segmentos.

`ref`/`ua`: Referer e User-Agent do link (cadastrados no canal), repassados ao
provedor e propagados pra todas as URLs reescritas. Ver proxy_rewrite.py.
"""

from urllib.parse import urlsplit

import requests
from fastapi import APIRouter, Depends, HTTPException, Query, Response

from ..db import get_db
from ..input_safety import clean_header_value
from ..proxy_rewrite import rewrite_manifest
from ..models import AccessToken
from ..safe_url import UnsafeUrlError, safe_get
from ..security import require_valid_token

router = APIRouter()

_TIMEOUT = 12
_MAX_BYTES = 2 * 1024 * 1024  # manifesto é pequeno; corta qualquer coisa gigante
_UA = "Mozilla/5.0 (SmartTV) AppleWebKit/537.36"


@router.get("/p/{token}/hls")
def hls_proxy(
    token: str,
    url: str = Query(..., description="URL do manifesto (encoded)"),
    ref: str | None = Query(None, description="Referer a enviar ao provedor"),
    ua: str | None = Query(None, description="User-Agent a enviar ao provedor"),
    full: int = Query(0, description="1 = segmentos e chaves também voltam pelo servidor"),
    _access: AccessToken = Depends(require_valid_token),
    db=Depends(get_db),
):
    if not url.startswith(("http://", "https://")):
        raise HTTPException(status_code=400, detail="url inválida")

    ref = clean_header_value(ref, 500) if ref else None
    ua = clean_header_value(ua, 500) if ua else None
    headers = {"User-Agent": ua or _UA}
    # sem Referer configurado no link, mantém o comportamento antigo só pro Pluto
    referer = ref or ("http://pluto.tv/" if "pluto.tv" in (urlsplit(url).hostname or "") else None)
    if referer:
        headers["Referer"] = referer
        parts = urlsplit(referer)
        if parts.scheme and parts.netloc:
            headers["Origin"] = f"{parts.scheme}://{parts.netloc}"
    try:
        # safe_get: bloqueia SSRF (hosts internos) em cada redirect
        r = safe_get(url, headers=headers, timeout=_TIMEOUT, stream=True)
    except UnsafeUrlError as e:
        raise HTTPException(status_code=400, detail=f"url não permitida: {e}")
    except requests.RequestException:
        raise HTTPException(status_code=502, detail="falha ao buscar o manifesto")
    if r.status_code >= 400:
        raise HTTPException(status_code=502, detail=f"origem respondeu {r.status_code}")

    final_url = str(r.url)
    body = r.raw.read(_MAX_BYTES, decode_content=True) or b""
    text = body.decode("utf-8", errors="replace")

    if not text.lstrip().startswith("#EXTM3U"):
        # não é m3u8 (ex: já é um .ts direto) — devolve como veio, com CORS
        return Response(
            content=body,
            media_type=r.headers.get("Content-Type", "application/octet-stream"),
            headers={"Access-Control-Allow-Origin": "*", "Cache-Control": "no-store"},
        )

    rewritten = rewrite_manifest(text, final_url, token, ref=ref, ua=ua, full=bool(full))
    return Response(
        content=rewritten,
        media_type="application/vnd.apple.mpegurl",
        headers={"Access-Control-Allow-Origin": "*", "Cache-Control": "no-store"},
    )
