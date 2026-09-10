<?php

namespace App\Support;

/**
 * Bloqueia SSRF: a aplicacao busca URLs digitadas por usuarios (listas IPTV),
 * entao precisa recusar qualquer alvo que resolva para rede privada/local antes
 * de qualquer requisicao (probe HTTP, ffprobe, download de M3U).
 */
class SafeUrl
{
    private const BLOCKED_RANGES = [
        '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
        '169.254.0.0/16', '0.0.0.0/8', '::1/128', 'fc00::/7', 'fe80::/10',
    ];

    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if (! $parts || empty($parts['scheme']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'])) {
            return false;
        }

        $host = $parts['host'] ?? null;
        if (! $host) {
            return false;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_filter([gethostbyname($host)]);

        if (empty($ips)) {
            return false; // nao resolveu -> recusa por seguranca
        }

        foreach ($ips as $ip) {
            foreach (self::BLOCKED_RANGES as $range) {
                if (self::ipInRange($ip, $range)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $range), 2, '32');

        if (str_contains($ip, ':') !== str_contains($subnet, ':')) {
            return false; // versoes de IP diferentes
        }

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~(0xFF >> $remainder) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
