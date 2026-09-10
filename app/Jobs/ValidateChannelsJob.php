<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelCheck;
use App\Models\ImportRun;
use App\Models\Playlist;
use App\Support\SafeUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Pool;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Valida um lote de canais em PARALELO dentro do proprio processo — nao um de
 * cada vez. O gargalo nunca foi a linguagem (PHP vs Python): era o loop
 * sequencial esperando cada requisicao terminar antes de comecar a proxima.
 * Aqui:
 *   1) todas as sondas HTTP do lote disparam juntas via Http::pool() (um
 *      unico curl_multi por baixo, sem thread nem terminal extra);
 *   2) o ffprobe dos que passaram no HTTP roda em processos assincronos
 *      (Symfony Process::start(), nao bloqueante), com um teto de
 *      concorrencia pra nao estourar CPU/rede da maquina.
 * Continua rodando com um unico "php artisan queue:work" — nao precisa abrir
 * mais terminal nenhum.
 */
class ValidateChannelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /** Quantos ffprobe rodando ao mesmo tempo, no maximo, por job. */
    private const FFPROBE_CONCURRENCY = 10;

    /**
     * @param  array<int>  $channelIds
     */
    public function __construct(
        private readonly array $channelIds,
        private readonly int $importRunId
    ) {
    }

    public function handle(): void
    {
        $channels = Channel::whereIn('id', $this->channelIds)->get();
        $playlistId = $channels->first()?->playlist_id;

        $results = $this->probeAll($channels);

        $okCount = 0;
        $failedCount = 0;

        foreach ($channels as $channel) {
            $result = $results[$channel->id];

            ChannelCheck::create([
                'channel_id' => $channel->id,
                'status' => $result['ok'] ? 'ok' : 'failed',
                'http_status' => $result['http_status'],
                'detected_content_type' => $result['content_type'],
                'ffprobe_ok' => $result['ffprobe_ok'],
                'message' => $result['message'],
                'checked_at' => now(),
            ]);

            $channel->update([
                'status' => $result['ok'] ? 'ok' : ($channel->consecutive_failures + 1 >= 3 ? 'dead' : 'failed'),
                'consecutive_failures' => $result['ok'] ? 0 : $channel->consecutive_failures + 1,
                'stream_type' => $result['stream_type'] ?? $channel->stream_type,
                'last_checked_at' => now(),
            ]);

            $result['ok'] ? $okCount++ : $failedCount++;
        }

        if ($playlistId && ($okCount || $failedCount)) {
            Playlist::where('id', $playlistId)->update([
                'ok_count' => DB::raw("ok_count + {$okCount}"),
                'failed_count' => DB::raw("failed_count + {$failedCount}"),
                'pending_count' => DB::raw('GREATEST(pending_count - ' . ($okCount + $failedCount) . ', 0)'),
            ]);
        }

        $importRun = ImportRun::find($this->importRunId);
        if (! $importRun) {
            return;
        }

        $importRun->increment('processed', $okCount + $failedCount);
        $importRun->increment('ok', $okCount);
        $importRun->increment('failed', $failedCount);
        $importRun->refresh();

        // "Ultimo job a sair apaga a luz": so um vence essa corrida porque o
        // UPDATE com WHERE status != completed so afeta 1 linha uma vez.
        if ($importRun->processed >= $importRun->total) {
            $won = ImportRun::where('id', $importRun->id)
                ->where('status', '!=', 'completed')
                ->update(['status' => 'completed']);

            if ($won) {
                FinalizeImportRunJob::dispatch($importRun->id, $playlistId ?? $importRun->playlist_id);
            }
        }
    }

    /**
     * @return array<int, array> resultado final por channel_id (ja com ffprobe
     *                           quando aplicavel)
     */
    private function probeAll($channels): array
    {
        $results = [];
        $toHttpProbe = [];

        // Filtra de cara quem nem chega a fazer request (SSRF, youtube).
        foreach ($channels as $channel) {
            if (! SafeUrl::isAllowed($channel->url)) {
                $results[$channel->id] = $this->fail('URL recusada (SSRF).');
                continue;
            }

            if (preg_match('#youtube\.com|youtu\.be#i', $channel->url)) {
                $results[$channel->id] = [
                    'ok' => true, 'http_status' => null, 'content_type' => 'youtube',
                    'ffprobe_ok' => null, 'message' => null, 'stream_type' => 'youtube',
                ];
                continue;
            }

            $toHttpProbe[] = $channel;
        }

        if (empty($toHttpProbe)) {
            return $results;
        }

        // 1) Todas as sondas HTTP do lote, de uma vez (curl_multi por baixo).
        $responses = Http::pool(fn (Pool $pool) => collect($toHttpProbe)->map(
            fn ($c) => $pool->as((string) $c->id)
                ->withHeaders(array_merge($c->http_headers ?? [], ['Range' => 'bytes=0-2048']))
                ->timeout(6)->connectTimeout(4)
                ->withOptions(['stream' => true])
                ->get($c->url)
        )->all());

        $needsFfprobe = []; // channel_id => [channel, headers]

        foreach ($toHttpProbe as $channel) {
            $response = $responses[(string) $channel->id];

            if ($response instanceof \Throwable) {
                $results[$channel->id] = $this->fail($response->getMessage());
                continue;
            }

            $contentType = $response->header('Content-Type', '');
            $httpOk = $response->successful() || $response->status() === 206;

            if (! $httpOk) {
                $results[$channel->id] = $this->fail("HTTP {$response->status()}", $response->status(), $contentType);
                continue;
            }

            if (str_contains($contentType, 'text/html')) {
                $results[$channel->id] = $this->fail('Content-Type text/html (provavelmente pagina, nao stream).', $response->status(), $contentType);
                continue;
            }

            $streamType = match (true) {
                str_contains($contentType, 'mpegurl') => 'hls',
                str_contains($contentType, 'mp4') => 'mp4',
                str_contains($contentType, 'matroska') => 'mkv',
                str_ends_with($channel->url, '.m3u8') => 'hls',
                str_ends_with($channel->url, '.mp4') => 'mp4',
                default => 'unknown',
            };

            $needsFfprobe[$channel->id] = [
                'channel' => $channel,
                'http_status' => $response->status(),
                'content_type' => $contentType,
                'stream_type' => $streamType,
            ];
        }

        // 2) ffprobe dos sobreviventes, em paralelo (com teto de concorrencia).
        foreach ($this->runFfprobePool($needsFfprobe) as $channelId => $ffprobeOk) {
            $info = $needsFfprobe[$channelId];
            $results[$channelId] = [
                'ok' => $ffprobeOk,
                'http_status' => $info['http_status'],
                'content_type' => $info['content_type'],
                'ffprobe_ok' => $ffprobeOk,
                'message' => $ffprobeOk ? null : 'ffprobe nao conseguiu abrir o stream.',
                'stream_type' => $info['stream_type'],
            ];
        }

        return $results;
    }

    /**
     * Roda ffprobe pra cada entrada em $needsFfprobe com no maximo
     * self::FFPROBE_CONCURRENCY processos simultaneos (janela deslizante).
     *
     * @return array<int, bool> channel_id => passou no ffprobe
     */
    private function runFfprobePool(array $needsFfprobe): array
    {
        $queue = array_values($needsFfprobe);
        $running = []; // channel_id => Process
        $done = [];

        while (! empty($queue) || ! empty($running)) {
            while (count($running) < self::FFPROBE_CONCURRENCY && ! empty($queue)) {
                $info = array_shift($queue);
                $channel = $info['channel'];
                $process = $this->buildFfprobeProcess($channel->url, $channel->http_headers ?? []);
                $process->start();
                $running[$channel->id] = $process;
            }

            usleep(100_000); // 100ms

            foreach ($running as $channelId => $process) {
                if ($process->isRunning()) {
                    continue;
                }

                $done[$channelId] = $process->isSuccessful() && trim($process->getOutput()) !== '';
                unset($running[$channelId]);
            }
        }

        return $done;
    }

    private function buildFfprobeProcess(string $url, array $headers): Process
    {
        $args = ['ffprobe', '-v', 'error', '-timeout', '6000000'];

        if (! empty($headers)) {
            $headerLines = collect($headers)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\r\n");
            $args[] = '-headers';
            $args[] = $headerLines . "\r\n";
        }

        $args = array_merge($args, ['-select_streams', 'v:0', '-show_entries', 'stream=codec_type', '-of', 'csv=p=0', $url]);

        $process = new Process($args);
        $process->setTimeout(10);

        return $process;
    }

    private function fail(string $message, ?int $httpStatus = null, ?string $contentType = null): array
    {
        return [
            'ok' => false,
            'http_status' => $httpStatus,
            'content_type' => $contentType,
            'ffprobe_ok' => null,
            'message' => $message,
        ];
    }
}
