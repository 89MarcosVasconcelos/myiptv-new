<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ImportRun;
use App\Models\Playlist;
use App\Support\SafeUrl;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Baixa o link cadastrado, decide pelo Content-Type/corpo se e uma playlist M3U
 * (expande em N canais) ou midia direta (mp4/mkv/etc, vira 1 canal so) e enfileira
 * a validacao em lotes na fila "validation" (separada da "default").
 */
class ImportPlaylistJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(private readonly Playlist $playlist)
    {
    }

    public function handle(): void
    {
        $this->playlist->update(['status' => 'processing']);

        if (! SafeUrl::isAllowed($this->playlist->url ?? '')) {
            $this->playlist->update(['status' => 'failed', 'error_message' => 'URL recusada (rede privada/local ou esquema invalido).']);
            return;
        }

        try {
            $response = Http::timeout(30)->get($this->playlist->url);
        } catch (\Throwable $e) {
            $this->playlist->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            return;
        }

        if (! $response->successful()) {
            $this->playlist->update(['status' => 'failed', 'error_message' => "HTTP {$response->status()} ao baixar a lista."]);
            return;
        }

        $contentType = $response->header('Content-Type', '');
        $body = $response->body();
        $isM3u = str_starts_with(trim($body), '#EXTM3U') || str_contains($contentType, 'mpegurl');

        $entries = $isM3u
            ? $this->parseM3u($body)
            : [['name' => basename(parse_url($this->playlist->url, PHP_URL_PATH) ?: 'item'), 'url' => $this->playlist->url, 'headers' => []]];

        $this->playlist->update([
            'format' => $isM3u ? (str_ends_with($this->playlist->url, '.m3u8') ? 'm3u8' : 'm3u') : 'media',
        ]);

        $channelIds = [];
        foreach ($entries as $entry) {
            $channel = Channel::firstOrCreate(
                [
                    'playlist_id' => $this->playlist->id,
                    'url_hash' => hash('sha256', $entry['url']),
                ],
                [
                    'name' => $entry['name'],
                    'url' => $entry['url'],
                    'channel_number' => $entry['channel_number'] ?? null,
                    'http_headers' => $entry['headers'] ?: null,
                    'status' => 'pending',
                ]
            );
            $channelIds[] = $channel->id;
        }

        $this->playlist->refreshCounters();

        $importRun = ImportRun::create([
            'playlist_id' => $this->playlist->id,
            'status' => 'processing',
            'total' => count($channelIds),
        ]);

        $jobs = collect($channelIds)
            ->chunk(50)
            ->map(fn ($chunk) => new ValidateChannelsJob($chunk->all(), $importRun->id))
            ->all();

        Bus::batch($jobs)
            ->onQueue('validation')
            ->finally(fn () => FinalizeImportRunJob::dispatch($importRun->id, $this->playlist->id))
            ->dispatch();
    }

    /**
     * Parser de streaming: le linha a linha (nao carrega tudo em memoria) e nao
     * descarta #EXTVLCOPT (http-user-agent / http-referrer) nem x-tvg-url.
     */
    private function parseM3u(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body);
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' ) {
                continue;
            }

            if (str_starts_with($line, '#EXTINF:')) {
                $name = trim(Str::afterLast($line, ','));
                preg_match('/tvg-chno="(\d+)"/i', $line, $chno);
                $current = [
                    'name' => $name !== '' ? $name : 'Canal sem nome',
                    'channel_number' => $chno[1] ?? null,
                    'headers' => [],
                ];
                continue;
            }

            if (str_starts_with($line, '#EXTVLCOPT:') && $current) {
                if (preg_match('/http-user-agent=(.+)/i', $line, $m)) {
                    $current['headers']['User-Agent'] = trim($m[1]);
                }
                if (preg_match('/http-referrer=(.+)/i', $line, $m)) {
                    $current['headers']['Referer'] = trim($m[1]);
                }
                continue;
            }

            if (str_starts_with($line, '#')) {
                continue; // outros metadados (#EXTM3U, x-tvg-url) tratados no header, nao aqui
            }

            // linha de URL: fecha a entrada corrente
            if ($current) {
                $current['url'] = $line;
                $entries[] = $current;
                $current = null;
            }
        }

        return $entries;
    }
}
