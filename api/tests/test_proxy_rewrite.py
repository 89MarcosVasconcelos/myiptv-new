"""Reescrita do manifesto HLS pelo proxy (mixed content / CORS)."""
from urllib.parse import parse_qs, quote, urlsplit

from app.proxy_rewrite import proxy_url, rewrite_manifest

T = "tok123"
MASTER = """#EXTM3U
#EXT-X-STREAM-INF:BANDWIDTH=800000
low/index.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=2000000
http://cdn.exemplo.com/alta?token=abc
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="a",URI="audio/pt.m3u8"
"""
MEDIA = """#EXTM3U
#EXT-X-VERSION:3
#EXT-X-KEY:METHOD=AES-128,URI="http://keys.exemplo.com/k1"
#EXT-X-MAP:URI="init.mp4"
#EXTINF:6.0,
http://seg.exemplo.com/a1.ts
#EXTINF:6.0,
https://seg.exemplo.com/a2.ts?x=1
#EXTINF:6.0,
rel/a3.ts
"""


def _target(proxied: str) -> str:
    return parse_qs(urlsplit(proxied).query)["url"][0]


def test_master_playlists_sempre_pelo_proxy_hls():
    out = rewrite_manifest(MASTER, "https://origem.tv/live/master.m3u8", T).splitlines()
    assert out[2].startswith(f"/p/{T}/hls?url=") and _target(out[2]) == "https://origem.tv/live/low/index.m3u8"
    # variante sem ".m3u8" na URL continua sendo playlist (vem logo após STREAM-INF)
    assert out[4].startswith(f"/p/{T}/hls?url=") and _target(out[4]) == "http://cdn.exemplo.com/alta?token=abc"
    assert f"/p/{T}/hls?url=" in out[5] and 'URI="/p/' in out[5]


def test_segmentos_http_voltam_pelo_media_e_https_ficam_diretos():
    out = rewrite_manifest(MEDIA, "https://origem.tv/live/media.m3u8", T).splitlines()
    key = out[2]
    assert f'URI="/p/{T}/media?url=' in key and "METHOD=AES-128" in key  # chave http -> media
    assert f"/p/{T}/media?url=" not in out[3]  # init.mp4 relativo resolvido em https -> direto
    assert 'URI="https://origem.tv/live/init.mp4"' in out[3]
    assert out[5].startswith(f"/p/{T}/media?url=") and _target(out[5]) == "http://seg.exemplo.com/a1.ts"
    assert out[7] == "https://seg.exemplo.com/a2.ts?x=1"
    assert out[9] == "https://origem.tv/live/rel/a3.ts"


def test_modo_full_manda_tudo_pelo_servidor():
    out = rewrite_manifest(MEDIA, "https://origem.tv/live/media.m3u8", T, full=True).splitlines()
    assert out[7].startswith(f"/p/{T}/media?url=") and _target(out[7]) == "https://seg.exemplo.com/a2.ts?x=1"
    assert out[9].startswith(f"/p/{T}/media?url=")
    assert 'URI="/p/' in out[3]


def test_full_propaga_nas_variantes_e_ref_ua_em_tudo():
    out = rewrite_manifest(MASTER, "https://o.tv/m.m3u8", T, ref="http://site.com/", ua="VLC/3", full=True).splitlines()
    q = parse_qs(urlsplit(out[2]).query)
    assert q["full"] == ["1"] and q["ref"] == ["http://site.com/"] and q["ua"] == ["VLC/3"]
    seg = rewrite_manifest(MEDIA, "http://o.tv/m.m3u8", T, ref="http://site.com/", ua="VLC/3").splitlines()[5]
    q = parse_qs(urlsplit(seg).query)
    assert q["ref"] == ["http://site.com/"] and q["ua"] == ["VLC/3"]


def test_proxy_url_codifica_e_nao_vaza_quebra_de_linha():
    u = proxy_url("a/b", "media", "http://x/y?a=1&b=2", ref="http://r/")
    assert u.startswith("/p/a%2Fb/media?url=" + quote("http://x/y?a=1&b=2", safe=""))
    assert "\n" not in u and "&ref=" in u
