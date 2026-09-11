<?php

namespace App\Services\Enrichment;

use App\Models\Channel;
use App\Models\ContentType;
use App\Models\Genre;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Preenche tipo, genero(s) e descricao de um canal buscando em fontes
 * externas gratuitas, na ordem: TMDb (filme/serie, mais completo) -> OMDb
 * (fallback, tambem filme/serie) -> TVmaze (fallback so pra serie) ->
 * iptv-org/api (canais AO VIVO de verdade — filme/serie nao tem categoria
 * la, so canal). Nunca sobrescreve o que ja foi preenchido manualmente: so
 * busca e grava os campos que ainda estao vazios.
 *
 * IMDb em si nao tem API gratuita/aberta (a oficial e vendida via AWS Data
 * Exchange, contrato comercial) — por isso essas alternativas.
 */
class ChannelEnricher
{
    /**
     * @param  bool  $force  "Preencher lacunas (total)": tenta os tres campos
     *                       mesmo que ja estejam preenchidos. Mesmo assim
     *                       NUNCA sobrescreve um valor existente — so grava
     *                       se achar algo novo (ver enrich() abaixo) — entao
     *                       rodar com force=true num canal ja completo
     *                       simplesmente nao muda nada nele.
     */
    public function enrich(Channel $channel, bool $force = false): void
    {
        $needsType = $force || is_null($channel->content_type_id);
        $needsGenre = $force || $channel->genres()->count() === 0;
        $needsDescription = $force || blank($channel->description);

        if (! $needsType && ! $needsGenre && ! $needsDescription) {
            return;
        }

        [$title, $year] = $this->parseTitle($channel->name);

        if ($title === '') {
            return;
        }

        $result = $this->tryTmdb($title, $year)
            ?? $this->tryOmdb($title, $year)
            ?? $this->tryTvmaze($title)
            ?? $this->tryIptvOrg($channel->name);

        if (! $result) {
            return;
        }

        $updates = [];

        // Guarda de novo aqui, com o valor ATUAL do canal (nao a flag
        // needsType/needsDescription, que no modo "total" foi forcada pra
        // true so pra garantir que a busca fosse tentada). Isso e o que
        // garante que "total" nunca sobrescreve um valor que o canal ja
        // tinha, mesmo tendo pesquisado de novo.
        if (is_null($channel->content_type_id) && ! empty($result['content_type_slug'])) {
            $updates['content_type_id'] = $this->resolveContentType($result['content_type_slug']);
        }

        if (blank($channel->description) && ! empty($result['description'])) {
            $updates['description'] = Str::limit($result['description'], 4990);
        }

        if ($updates) {
            $channel->update($updates);
        }

        if ($needsGenre && ! empty($result['genres'])) {
            $ids = collect($result['genres'])
                ->filter()
                ->map(fn ($name) => $this->resolveGenre($name))
                ->unique()
                ->values()
                ->all();

            if ($ids) {
                $channel->genres()->syncWithoutDetaching($ids);
            }
        }
    }

    /**
     * Nomes de canal IPTV costumam vir com ruido — tags de qualidade,
     * temporada/episodio, ano. Limpa isso pra sobrar so o titulo que faz
     * sentido buscar, e separa o ano quando da pra achar um (ajuda a
     * desempatar buscas com titulo repetido, tipo remakes).
     */
    private function parseTitle(string $name): array
    {
        $clean = $name;
        $clean = preg_replace('/\[[^\]]*\]/', ' ', $clean);
        $clean = preg_replace('/\b(FHD|UHD|HD|SD|4K|H\.?265|H\.?264|LEGENDADO|DUBLADO|LEG|DUB)\b/i', ' ', $clean);
        $clean = preg_replace('/\bS\d{1,2}E\d{1,3}\b/i', ' ', $clean);
        $clean = preg_replace('/\bT\d{1,2}\s*E\d{1,3}\b/i', ' ', $clean);

        $year = null;
        if (preg_match('/\(((19|20)\d{2})\)/', $clean, $m) || preg_match('/-\s*((19|20)\d{2})\s*$/', $clean, $m)) {
            $year = (int) $m[1];
            $clean = str_replace($m[0], ' ', $clean);
        }

        $clean = trim(preg_replace('/\s{2,}/', ' ', $clean), " -|:._");

        return [$clean, $year];
    }

    /**
     * Cada fonte externa roda dentro deste wrapper: se a rede falhar
     * (timeout, SSL, DNS, conexao recusada — muito comum com o worker rodando
     * atras de antivirus/proxy corporativo que faz inspecao HTTPS), a fonte
     * so devolve null (como "nao achou nada") e a cadeia segue pra proxima
     * fonte, EM VEZ de estourar uma excecao e derrubar o job inteiro.
     *
     * BUG CORRIGIDO AQUI: antes, uma excecao de conexao dentro de qualquer
     * tryXxx() (ex.: "cURL error 60: SSL certificate problem") propagava
     * direto pra fora de enrich(), marcando TODO o job como falho e
     * disparando retry — isso aconteceu de verdade em ~11 mil canais de uma
     * vez (botao "Preencher lacunas" sem as chaves de API configuradas),
     * cada um tentando e falhando, monopolizando o unico worker por horas e
     * dando a impressao de o sistema inteiro estar travado.
     */
    private function safely(callable $fn): ?array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function tryTmdb(string $title, ?int $year): ?array
    {
        return $this->safely(function () use ($title, $year) {
            $key = config('services.tmdb.key');
            if (! $key) {
                return null;
            }

            $response = Http::timeout(8)->get('https://api.themoviedb.org/3/search/multi', array_filter([
                'api_key' => $key,
                'query' => $title,
                'language' => 'pt-BR',
                'year' => $year,
            ]));

            if (! $response->ok()) {
                return null;
            }

            $item = collect($response->json('results', []))
                ->first(fn ($r) => in_array($r['media_type'] ?? null, ['movie', 'tv']));

            if (! $item) {
                return null;
            }

            $isMovie = $item['media_type'] === 'movie';
            $genreMap = $this->tmdbGenreMap($isMovie ? 'movie' : 'tv', $key);
            $genres = collect($item['genre_ids'] ?? [])
                ->map(fn ($id) => $genreMap[$id] ?? null)
                ->filter()
                ->values()
                ->all();

            return [
                'content_type_slug' => $isMovie ? 'filme' : 'serie',
                'description' => $item['overview'] ?: null,
                'genres' => $genres,
            ];
        });
    }

    private function tmdbGenreMap(string $mediaType, string $key): array
    {
        return Cache::remember("tmdb_genres_{$mediaType}", now()->addDays(7), function () use ($mediaType, $key) {
            try {
                $response = Http::timeout(8)->get("https://api.themoviedb.org/3/genre/{$mediaType}/list", [
                    'api_key' => $key,
                    'language' => 'pt-BR',
                ]);

                return $response->ok() ? collect($response->json('genres', []))->pluck('name', 'id')->all() : [];
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    private function tryOmdb(string $title, ?int $year): ?array
    {
        return $this->safely(function () use ($title, $year) {
            $key = config('services.omdb.key');
            if (! $key) {
                return null;
            }

            $response = Http::timeout(8)->get('https://www.omdbapi.com/', array_filter([
                'apikey' => $key,
                't' => $title,
                'y' => $year,
                'plot' => 'short',
            ]));

            $data = $response->json();

            if (! $response->ok() || ($data['Response'] ?? 'False') === 'False') {
                return null;
            }

            $slug = match ($data['Type'] ?? null) {
                'movie' => 'filme',
                'series' => 'serie',
                default => null,
            };

            $genres = collect(explode(',', $data['Genre'] ?? ''))
                ->map(fn ($g) => trim($g))
                ->filter(fn ($g) => $g && $g !== 'N/A')
                ->values()
                ->all();

            $plot = (! empty($data['Plot']) && $data['Plot'] !== 'N/A') ? $data['Plot'] : null;

            if (! $slug && ! $genres && ! $plot) {
                return null;
            }

            return ['content_type_slug' => $slug, 'description' => $plot, 'genres' => $genres];
        });
    }

    /** So cobre serie (TVmaze e um catalogo de programas de TV, sem filme). */
    private function tryTvmaze(string $title): ?array
    {
        return $this->safely(function () use ($title) {
            $response = Http::timeout(8)->get('https://api.tvmaze.com/singlesearch/shows', ['q' => $title]);

            if (! $response->ok()) {
                return null;
            }

            $data = $response->json();
            if (! $data) {
                return null;
            }

            $summary = ! empty($data['summary']) ? trim(strip_tags($data['summary'])) : null;
            $genres = $data['genres'] ?? [];

            if (! $summary && ! $genres) {
                return null;
            }

            return ['content_type_slug' => 'serie', 'description' => $summary, 'genres' => $genres];
        });
    }

    /**
     * Ultimo recurso: dataset publico do iptv-org com metadados de canal AO
     * VIVO de verdade (nao tem filme/serie). Sem chave, sem limite de taxa —
     * e um JSON estatico, cacheado localmente por alguns dias.
     */
    private function tryIptvOrg(string $channelName): ?array
    {
        return $this->safely(function () use ($channelName) {
            $channels = Cache::remember('iptvorg_channels', now()->addDays(3), function () {
                try {
                    $response = Http::timeout(15)->get('https://iptv-org.github.io/api/channels.json');

                    return $response->ok() ? $response->json() : [];
                } catch (\Throwable $e) {
                    return [];
                }
            });

            if (! $channels) {
                return null;
            }

            $normalized = $this->normalize($channelName);

            foreach ($channels as $c) {
                $names = array_merge([$c['name'] ?? ''], $c['alt_names'] ?? []);
                foreach ($names as $name) {
                    if ($name && $this->normalize($name) === $normalized) {
                        return [
                            'content_type_slug' => 'canal',
                            'description' => null,
                            'genres' => $c['categories'] ?? [],
                        ];
                    }
                }
            }

            return null;
        });
    }

    private function normalize(string $value): string
    {
        $value = Str::ascii($value);
        $value = strtolower($value);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    private function resolveContentType(string $slug): int
    {
        return ContentType::firstOrCreate(['slug' => $slug], ['slug' => $slug, 'name' => ucfirst($slug)])->id;
    }

    private function resolveGenre(string $name): int
    {
        $slug = Str::slug($name);

        return Genre::firstOrCreate(['slug' => $slug], ['slug' => $slug, 'name' => $name])->id;
    }
}
