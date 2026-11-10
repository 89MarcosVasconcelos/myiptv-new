"""Teste de FLUXO CONTÍNUO de um link de canal ("Validar fluxo contínuo").

O health-check normal (stream_validation.check_stream) só lê os primeiros 64 KB
e confere se parecem vídeo. Isso aprova links que, na prática, quebram minutos
depois (achado em 2026-11-10, painel 31.43.191.125:8080): o provedor entrega o
início do stream e depois segura a conexão sem mandar mais nada, responde 401/502
quando há conexões demais, etc. Aqui o teste é o que o player faria:

- stream bruto (TS/MP4 sem manifesto): lê por alguns segundos e exige que os
  dados continuem chegando (sem parar, sem travar, ritmo mínimo);
- HLS (.m3u8): segue master -> playlist de mídia, e BAIXA de verdade os últimos
  segmentos (a ponta ao vivo), conferindo que são vídeo e que baixam em tempo
  menor que a duração deles.

Resultado em 3 estados:
  FLUXO_OK            entregou fluxo
  FLUXO_FALHA         não entregou (travou, parou, 5xx, página no lugar do vídeo...)
  FLUXO_INCONCLUSIVO  só em HLS: o servidor recusou o segmento com 401/403/429 do IP
                      da VPS, mas o navegador baixa os segmentos direto -- pode tocar.
                      Em stream bruto isso é FALHA, porque ele passa pelo proxy
                      /media do próprio servidor (mesmo IP que está sendo testado).

Devolve (estado, motivo curto em português) — o motivo vai pra tela.
"""

import time
from urllib.parse import urljoin

import requests

from .safe_url import UnsafeUrlError, assert_public_http_url
from .stream_validation import (
    _DEFAULT_USER_AGENT,
    _corpo_e_html,
    _first_variant_uri,
    _looks_like_mp4,
    _looks_like_raw_mpeg_ts,
)

FLUXO_OK = "ok"
FLUXO_FALHA = "falha"
FLUXO_INCONCLUSIVO = "inconclusivo"

CONNECT_TIMEOUT = 6.0
READ_TIMEOUT = 4.0            # sem receber nada por 4 s = travou
FLOW_SECONDS = 6.0            # quanto tempo lemos um stream bruto
MAX_GAP_SEC = 3.5             # maior pausa aceita entre pedaços de dados
MIN_FLOW_BYTES = 64 * 1024    # mínimo recebido na janela
MAX_FLOW_BYTES = 25 * 1024 * 1024
SEGMENTS_TO_TEST = 2
MAX_SEGMENT_BYTES = 4 * 1024 * 1024
MAX_MANIFEST_BYTES = 512 * 1024
MIN_SEGMENT_BYTES = 4096

_BLOQUEIO = {401, 403, 429}


def _headers(extra: dict | None) -> dict:
    return {"User-Agent": _DEFAULT_USER_AGENT, **(extra or {})}


def _guard(url: str) -> bool:
    try:
        assert_public_http_url(url)
        return True
    except UnsafeUrlError:
        return False


def _is_media_bytes(data: bytes) -> bool:
    """TS (sync byte repetindo), MP4/fMP4 (ftyp/styp/moof/moov) ou áudio puro (ID3/ADTS)."""
    if _looks_like_raw_mpeg_ts(data) or _looks_like_mp4(data):
        return True
    if len(data) >= 8 and data[4:8] in (b"styp", b"moof", b"moov", b"sidx"):
        return True
    if data[:3] == b"ID3":
        return True
    return len(data) >= 2 and data[0] == 0xFF and (data[1] & 0xF0) == 0xF0


def _status_reason(code: int) -> str:
    return {401: "401 (credencial/limite de conexões)", 403: "403 (acesso negado)",
            429: "429 (limite de requisições)", 404: "404 (não existe)", 410: "410 (removido)"}.get(
        code, f"HTTP {code}")


def _read_text(chunks, limit: int) -> str:
    """Lê texto de uma resposta (ou do iterador de pedaços dela) até `limit` bytes."""
    if hasattr(chunks, "iter_content"):
        chunks = chunks.iter_content(chunk_size=8192)
    buf, total = [], 0
    for chunk in chunks:
        if not chunk:
            break
        buf.append(chunk)
        total += len(chunk)
        if total >= limit:
            break
    return b"".join(buf).decode("utf-8", errors="replace")


def _manifest_segments(text: str, base: str) -> list[tuple[str, float, bool]]:
    """[(url, duração em s, criptografado)] na ordem da playlist de mídia."""
    out, dur, encrypted = [], 0.0, False
    for raw in text.splitlines():
        line = raw.strip()
        if not line:
            continue
        if line.startswith("#EXT-X-KEY"):
            encrypted = "METHOD=NONE" not in line.upper()
        elif line.startswith("#EXTINF:"):
            try:
                dur = float(line[8:].split(",")[0])
            except ValueError:
                dur = 0.0
        elif not line.startswith("#"):
            out.append((urljoin(base, line), dur, encrypted))
            dur = 0.0
    return out


# --- stream bruto: lê por alguns segundos e confere se continua chegando ---

def _check_raw(resp: requests.Response, it, first: bytes, started: float) -> tuple[str, str]:
    if _corpo_e_html(first):
        return FLUXO_FALHA, "devolveu uma página em vez de vídeo"
    ctype = (resp.headers.get("Content-Type") or "").lower()
    if not _is_media_bytes(first) and not ("mp2t" in ctype or "mp4" in ctype or "mpeg" in ctype):
        return FLUXO_FALHA, "o conteúdo não parece vídeo"
    total = len(first)
    t0 = started
    last = time.monotonic()
    max_gap = last - t0          # inclui a espera pelo 1º dado
    deadline = t0 + FLOW_SECONDS
    cut_early = True             # vira False se saiu do laço por tempo/volume, e não por EOF
    try:
        for chunk in it:
            now = time.monotonic()
            if not chunk:
                continue
            max_gap = max(max_gap, now - last)
            last = now
            total += len(chunk)
            if now >= deadline or total >= MAX_FLOW_BYTES:
                cut_early = False
                break
    except requests.RequestException:
        return FLUXO_FALHA, f"travou: parou de enviar dados após {total // 1024} KB"
    end = time.monotonic()
    if cut_early:
        return FLUXO_FALHA, f"o servidor encerrou a conexão após {end - t0:.0f} s ({total // 1024} KB)"
    if total < MIN_FLOW_BYTES:
        return FLUXO_FALHA, f"só enviou {total // 1024} KB em {FLOW_SECONDS:.0f} s"
    if max_gap > MAX_GAP_SEC:
        return FLUXO_FALHA, f"pausa de {max_gap:.1f} s sem dados (trava no player)"
    return FLUXO_OK, f"{total // 1024} KB em {min(end - t0, FLOW_SECONDS):.0f} s, sem pausas"


# --- HLS: segue a cadeia e baixa os últimos segmentos ---

def _get(url: str, headers: dict | None, stream: bool = True):
    return requests.get(url, headers=_headers(headers), timeout=(CONNECT_TIMEOUT, READ_TIMEOUT),
                        stream=stream, allow_redirects=True)


def _check_hls(first_text: str, base_url: str, headers: dict | None) -> tuple[str, str]:
    text, base = first_text, base_url
    for _ in range(3):  # master -> media (às vezes mais de um nível)
        variant = _first_variant_uri(text)
        if variant is None:
            break
        base = urljoin(base, variant)
        if not _guard(base):
            return FLUXO_INCONCLUSIVO, "endereço interno ignorado"
        try:
            with _get(base, headers) as r:
                if r.status_code >= 400:
                    return (FLUXO_INCONCLUSIVO if r.status_code in _BLOQUEIO else FLUXO_FALHA,
                            f"playlist da variante: {_status_reason(r.status_code)}")
                text = _read_text(r, MAX_MANIFEST_BYTES)
                base = str(r.url)
        except requests.RequestException:
            return FLUXO_FALHA, "playlist da variante não respondeu"
        if not text.lstrip().startswith("#EXTM3U"):
            return FLUXO_FALHA, "a variante não é uma playlist"
    segs = _manifest_segments(text, base)
    if not segs:
        return FLUXO_FALHA, "playlist sem segmentos"
    tested = segs[-SEGMENTS_TO_TEST:]
    ok_count = blocked = 0
    for url, dur, encrypted in tested:
        if not _guard(url):
            blocked += 1
            continue
        t0 = time.monotonic()
        try:
            with _get(url, headers) as r:
                if r.status_code >= 400:
                    if r.status_code in _BLOQUEIO:
                        blocked += 1
                        continue
                    return FLUXO_FALHA, f"segmento: {_status_reason(r.status_code)}"
                data = b""
                total = 0
                chunks = []
                for chunk in r.iter_content(chunk_size=16384):
                    if not chunk:
                        continue
                    chunks.append(chunk)
                    total += len(chunk)
                    if total >= MAX_SEGMENT_BYTES:
                        break
                data = b"".join(chunks)
        except requests.RequestException:
            return FLUXO_FALHA, "segmento travou no download"
        elapsed = time.monotonic() - t0
        if total < MIN_SEGMENT_BYTES:
            return FLUXO_FALHA, f"segmento vazio ({total} bytes)"
        if not encrypted and not _is_media_bytes(data):
            return FLUXO_FALHA, "o segmento não é vídeo"
        limit = max(2.0 * dur, 8.0) if dur else 12.0
        if elapsed > limit:
            return FLUXO_FALHA, f"segmento lento: {elapsed:.1f} s para {dur:.0f} s de vídeo"
        ok_count += 1
    if ok_count == len(tested):
        return FLUXO_OK, f"{ok_count} segmento(s) baixado(s) a tempo"
    if ok_count == 0 and blocked:
        return FLUXO_INCONCLUSIVO, "segmentos recusados do IP do servidor (o navegador pode baixar)"
    return FLUXO_OK, f"{ok_count} de {len(tested)} segmentos baixados"


def check_flow(url: str, headers: dict | None = None) -> tuple[str, str]:
    """Testa se o link entrega fluxo contínuo. Nunca levanta exceção."""
    if not _guard(url):
        return FLUXO_INCONCLUSIVO, "endereço interno ignorado"
    started = time.monotonic()
    try:
        with _get(url, headers) as resp:
            if resp.status_code >= 400:
                # stream bruto e HLS sem 4xx: quem toca é o servidor (proxy) ou o navegador
                # direto. Para um 401/403/429 já no 1º pedido não dá pra distinguir -- como o
                # manifesto/stream passa SEMPRE pelo proxy do servidor, é falha de verdade.
                return FLUXO_FALHA, _status_reason(resp.status_code)
            first = b""
            it = resp.iter_content(chunk_size=16384)  # UM iterador só: continua de onde parou
            for chunk in it:
                if chunk:
                    first += chunk
                    if len(first) >= 4096:
                        break
            if not first:
                return FLUXO_FALHA, "abriu a conexão mas não enviou dados"
            head = first[:64].lstrip()
            if head.startswith(b"#EXTM3U"):
                text = first.decode("utf-8", errors="replace") + _read_text(it, MAX_MANIFEST_BYTES)
                return _check_hls(text, str(resp.url), headers)
            return _check_raw(resp, it, first, started)
    except requests.Timeout:
        return FLUXO_FALHA, "demorou demais para responder (travou)"
    except requests.RequestException as e:
        return FLUXO_FALHA, f"conexão falhou ({type(e).__name__})"
    except Exception as e:  # noqa: BLE001 - um link ruim nunca derruba o lote
        return FLUXO_FALHA, f"erro inesperado ({type(e).__name__})"
