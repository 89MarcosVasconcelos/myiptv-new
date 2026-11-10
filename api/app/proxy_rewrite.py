"""Reescrita de manifestos HLS para o proxy (hls_proxy.py).

Por que existe: o site roda em https, e muito link HLS aponta pra segmentos
http:// (o navegador bloqueia "mixed content") ou pra https sem CORS (o hls.js
não consegue ler). Em vez de deixar segmentos/chaves como URLs absolutas
diretas, o manifesto devolvido pelo nosso servidor faz esses pedidos voltarem
pelo nosso domínio (mesma origem, https):

- sub-playlists (.m3u8)            -> sempre /p/{token}/hls
- segmentos / chaves / init (MAP)  -> /p/{token}/media quando o endereço é http://
                                      (mixed content) OU quando `full` está ligado
                                      (https sem CORS); senão ficam diretos, que é o
                                      mais barato (o vídeo pesado não passa pelo VPS).

Referer e User-Agent configurados no link (`ref`/`ua`) acompanham TODOS os
pedidos reescritos, pra o provedor ver o mesmo cabeçalho do primeiro.
"""

import re
from urllib.parse import quote, urljoin

# tags cujo URI="..." é SEMPRE outra playlist
_PLAYLIST_URI_TAGS = ("#EXT-X-MEDIA", "#EXT-X-I-FRAME-STREAM-INF")


def proxy_url(token: str, kind: str, target: str, ref: str | None = None, ua: str | None = None,
              full: bool = False) -> str:
    """kind: "hls" (manifesto) ou "media" (segmento/chave/arquivo)."""
    out = f"/p/{quote(token, safe='')}/{kind}?url={quote(target, safe='')}"
    if ref:
        out += f"&ref={quote(ref, safe='')}"
    if ua:
        out += f"&ua={quote(ua, safe='')}"
    if full and kind == "hls":
        out += "&full=1"
    return out


def _resource(token, abs_u, ref, ua, full):
    if full or abs_u.lower().startswith("http://"):
        return proxy_url(token, "media", abs_u, ref, ua)
    return abs_u


def rewrite_manifest(text: str, base_url: str, token: str, ref: str | None = None,
                     ua: str | None = None, full: bool = False) -> str:
    """Devolve o m3u8 com todas as URIs apontando pro proxy (ou diretas, se https e sem `full`)."""
    out = []
    next_is_variant = False
    for raw in text.splitlines():
        line = raw.strip()
        if not line:
            out.append(raw)
            continue
        if line.startswith("#"):
            is_playlist_tag = line.startswith(_PLAYLIST_URI_TAGS)

            def repl(m, is_playlist_tag=is_playlist_tag):
                abs_u = urljoin(base_url, m.group(1))
                if is_playlist_tag or ".m3u8" in abs_u.lower():
                    return f'URI="{proxy_url(token, "hls", abs_u, ref, ua, full)}"'
                return f'URI="{_resource(token, abs_u, ref, ua, full)}"'

            if line.startswith("#EXT-X-STREAM-INF"):
                next_is_variant = True  # a próxima linha sem "#" é a playlist da variante
            out.append(re.sub(r'URI="([^"]+)"', repl, raw))
            continue
        abs_u = urljoin(base_url, line)
        if next_is_variant or ".m3u8" in abs_u.lower():
            out.append(proxy_url(token, "hls", abs_u, ref, ua, full))
        else:
            out.append(_resource(token, abs_u, ref, ua, full))
        next_is_variant = False
    return "\n".join(out) + "\n"
