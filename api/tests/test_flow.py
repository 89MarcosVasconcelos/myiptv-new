"""Testes do check_flow com um servidor HTTP falso (comportamentos reais de provedor IPTV)."""
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import pytest

from app import stream_flow
from app.stream_flow import FLUXO_FALHA, FLUXO_INCONCLUSIVO, FLUXO_OK, check_flow

TS = (b"\x47" + b"\x00" * 187)


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):  # silencia
        pass

    def _send(self, code, body=b"", ctype="video/mp2t"):
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):  # noqa: N802
        p = self.path
        try:
            if p.startswith("/continuo"):
                self.send_response(200); self.send_header("Content-Type", "video/mp2t"); self.end_headers()
                end = time.time() + 12
                while time.time() < end:
                    self.wfile.write(TS * 100)  # ~18 KB
                    time.sleep(0.05)
            elif p.startswith("/burst-trava"):
                self.send_response(200); self.send_header("Content-Type", "video/mp2t"); self.end_headers()
                self.wfile.write(TS * 700)  # ~131 KB de uma vez e depois silêncio
                self.wfile.flush()
                time.sleep(12)
            elif p.startswith("/fecha-cedo"):
                self.send_response(200); self.send_header("Content-Type", "video/mp2t"); self.end_headers()
                self.wfile.write(TS * 200)
            elif p.startswith("/401"):
                self._send(401, b"no")
            elif p.startswith("/html"):
                self._send(200, b"<html>Welcome to nginx!</html>" + b" " * 5000, "video/mp2t")
            elif p == "/hls-ok.m3u8":
                self._send(200, b"#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nmedia.m3u8\n", "application/vnd.apple.mpegurl")
            elif p == "/media.m3u8":
                self._send(200, b"#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6.0,\nseg1.ts\n#EXTINF:6.0,\nseg2.ts\n", "application/vnd.apple.mpegurl")
            elif p in ("/seg1.ts", "/seg2.ts"):
                self._send(200, TS * 100)
            elif p == "/hls-404seg.m3u8":
                self._send(200, b"#EXTM3U\n#EXTINF:6.0,\nnaoexiste.ts\n", "application/vnd.apple.mpegurl")
            elif p == "/hls-htmlseg.m3u8":
                self._send(200, b"#EXTM3U\n#EXTINF:6.0,\nhtmlseg.ts\n", "application/vnd.apple.mpegurl")
            elif p == "/htmlseg.ts":
                self._send(200, b"<html>erro</html>" + b" " * 6000)
            elif p == "/hls-403seg.m3u8":
                self._send(200, b"#EXTM3U\n#EXTINF:6.0,\n403seg.ts\n", "application/vnd.apple.mpegurl")
            elif p == "/403seg.ts":
                self._send(403, b"x")
            elif p == "/hls-vazio.m3u8":
                self._send(200, b"#EXTM3U\n#EXT-X-TARGETDURATION:6\n", "application/vnd.apple.mpegurl")
            else:
                self._send(404, b"nope")
        except (BrokenPipeError, ConnectionResetError):
            pass


@pytest.fixture(scope="module")
def base():
    srv = ThreadingHTTPServer(("127.0.0.1", 0), H)
    srv.daemon_threads = True
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    yield f"http://127.0.0.1:{srv.server_address[1]}"
    srv.shutdown()


@pytest.fixture(autouse=True)
def _sem_ssrf(monkeypatch):
    monkeypatch.setattr(stream_flow, "_guard", lambda url: True)


def test_continuo_ok(base):
    estado, nota = check_flow(base + "/continuo")
    assert estado == FLUXO_OK, nota


def test_burst_e_trava(base):
    estado, nota = check_flow(base + "/burst-trava")
    assert estado == FLUXO_FALHA and ("travou" in nota or "pausa" in nota), nota


def test_fecha_cedo(base):
    estado, nota = check_flow(base + "/fecha-cedo")
    assert estado == FLUXO_FALHA, nota


def test_401_html_404(base):
    # 401/403/429 = servidor recusou (limite de conexões/bloqueio de IP): inconclusivo, NÃO falha
    estado, nota = check_flow(base + "/401")
    assert estado == FLUXO_INCONCLUSIVO and "não conta" in nota
    assert check_flow(base + "/html")[0] == FLUXO_FALHA
    assert check_flow(base + "/x404")[0] == FLUXO_FALHA


def test_hls_ok(base):
    estado, nota = check_flow(base + "/hls-ok.m3u8")
    assert estado == FLUXO_OK, nota


def test_hls_segmento_inexistente_ou_html(base):
    assert check_flow(base + "/hls-404seg.m3u8")[0] == FLUXO_FALHA
    assert check_flow(base + "/hls-htmlseg.m3u8")[0] == FLUXO_FALHA
    assert check_flow(base + "/hls-vazio.m3u8")[0] == FLUXO_FALHA


def test_hls_segmento_403_inconclusivo(base):
    assert check_flow(base + "/hls-403seg.m3u8")[0] == FLUXO_INCONCLUSIVO


def test_queda_de_internet_nao_condena_links(base, monkeypatch):
    """Sem rede no servidor, a falha NÃO pode ser gravada como link instável."""
    from app import stream_cleanup

    monkeypatch.setattr(stream_cleanup, "_internet_ok", lambda: False)
    monkeypatch.setattr(stream_cleanup.time, "sleep", lambda s: None)
    sid, estado, nota = stream_cleanup._flow_check((1, base + "/html", None, None))
    assert estado == FLUXO_INCONCLUSIVO and "sem internet" in nota
    monkeypatch.setattr(stream_cleanup, "_internet_ok", lambda: True)
    assert stream_cleanup._flow_check((1, base + "/html", None, None))[1] == FLUXO_FALHA
