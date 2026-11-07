"""Proteção contra SSRF (Server-Side Request Forgery).

Trazido do projeto Laravel anterior (classe SafeUrl do PHP): qualquer coisa que
o servidor busque a pedido de um usuário (proxy de manifesto HLS, proxy de
mídia, importação de lista M3U por URL) passa por aqui antes. Sem isso, um
usuário com token poderia mandar a API acessar http://mysql:3306, o Docker
socket, serviços internos do Coolify ou o endpoint de metadados da cloud.

Regras:
- só http/https;
- o host precisa resolver SÓ pra endereços públicos (nada de loopback,
  privado, link-local, multicast, reservado, CGNAT);
- redirecionamentos são seguidos à mão, e CADA salto é revalidado (um host
  público pode responder 302 pra http://169.254.169.254/).

Limitação conhecida: existe uma janela mínima entre resolver o DNS aqui e a
conexão do requests (DNS rebinding). Mitigação razoável pro nosso caso: as
rotas já exigem token/login. Não substitui isolamento de rede.
"""

import ipaddress
import socket
from urllib.parse import urljoin, urlparse

import requests

MAX_REDIRECTS = 5

_CGNAT = ipaddress.ip_network("100.64.0.0/10")


class UnsafeUrlError(ValueError):
    """URL recusada por apontar pra fora do que é seguro buscar."""


def _is_public(ip: ipaddress._BaseAddress) -> bool:
    if isinstance(ip, ipaddress.IPv6Address) and ip.ipv4_mapped is not None:
        ip = ip.ipv4_mapped
    if (
        ip.is_private
        or ip.is_loopback
        or ip.is_link_local
        or ip.is_multicast
        or ip.is_reserved
        or ip.is_unspecified
    ):
        return False
    if isinstance(ip, ipaddress.IPv4Address) and ip in _CGNAT:
        return False
    return True


def assert_public_http_url(url: str) -> str:
    """Levanta UnsafeUrlError se a URL não for http(s) pra um host público."""
    parsed = urlparse(url or "")
    if parsed.scheme not in ("http", "https"):
        raise UnsafeUrlError("só links http:// ou https:// são aceitos")
    host = parsed.hostname
    if not host:
        raise UnsafeUrlError("URL sem host")

    try:
        infos = socket.getaddrinfo(host, parsed.port or (443 if parsed.scheme == "https" else 80), proto=socket.IPPROTO_TCP)
    except socket.gaierror:
        raise UnsafeUrlError("host não resolve")

    addrs = {info[4][0] for info in infos}
    if not addrs:
        raise UnsafeUrlError("host não resolve")
    for addr in addrs:
        try:
            ip = ipaddress.ip_address(addr.split("%")[0])
        except ValueError:
            raise UnsafeUrlError("endereço inválido")
        if not _is_public(ip):
            raise UnsafeUrlError("endereço interno/privado não é permitido")
    return url


def safe_get(url: str, *, headers=None, timeout=15, stream=True) -> requests.Response:
    """requests.get com validação anti-SSRF em CADA salto de redirect.
    Levanta UnsafeUrlError (URL recusada) ou requests.RequestException."""
    current = url
    for _ in range(MAX_REDIRECTS + 1):
        assert_public_http_url(current)
        resp = requests.get(current, headers=headers, timeout=timeout, stream=stream, allow_redirects=False)
        if resp.status_code in (301, 302, 303, 307, 308) and resp.headers.get("Location"):
            current = urljoin(current, resp.headers["Location"])
            resp.close()
            continue
        return resp
    raise UnsafeUrlError("redirecionamentos demais")
