<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelCheck;
use App\Models\ImportRun;
use App\Models\Playlist;
use App\Support\SafeUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Valida um lote de canais: 200 OK no probe HTTP nao basta (muito servidor de IPTV
 * mente), entao o ffprobe confirma se o stream realmente abre. Roda na fila
 * "validation" para nao competir com a "default" numa importacao grande.
 *
 * Sem Bus::batch aqui de proposito: pra importacoes de milhares de canais, o
 * batch guarda um closure serializado com o estado de progresso e isso
 * chegou a estourar o memory_limit do PHP. Em vez disso, cada job atualiza os
 * contadores da playlist e do import_run diretamente (dá a barra de progresso
 * em tempo real de graca) e o ULTIMO job a terminar (processed >= total)
 * finaliza o import_run sozinho.
 */
class ValidateChannelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

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

        $okCount = 0;
        $failedCount = 0;

        foreach ($channels as $channel) {
            $result = $this->validateChannel($channel);

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

    private function validateChannel(Channel $channel): array
    {
        if (! SafeUrl::isAllowed($channel->url)) {
            return ['ok' => false, 'http_status' => null, 'content_type' => null, 'ffprobe_ok' => null, 'message' => 'URL recusada (SSRF).'];
        }

        // YouTube live nao passa por HTTP probe/ffprobe: e valido, mas o player usa embed.
        if (preg_match('#youtube\.com|youtu\.be#i', $channel->url)) {
            return ['ok' => true, 'http_status' => null, 'content_type' => 'youtube', 'ffprobe_ok' => null, 'message' => null, 'stream_type' => 'youtube'];
        }

        try {
            $headers = $channel->http_headers ?? [];
            $response = Http::withHeaders($headers)->timeout(6)->connectTimeout(4)->withOptions(['stream' => true])
                ->withHeaders(['Range' => 'bytes=0-2048'])
                ->get($channel->url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'http_status' => null, 'content_type' => null, 'ffprobe_ok' => null, 'message' => $e->getMessage()];
        }

        $contentType = $response->header('Content-Type', '');
        $httpOk = $response->successful() || $response->status() === 206;

        if (! $httpOk) {
            return ['ok' => false, 'http_status' => $response->status(), 'content_type' => $contentType, 'ffprobe_ok' => null, 'message' => "HTTP {$response->status()}"];
        }

        // resolve o stream_type pelo Content-Type quando a URL nao tem extensao (comum no Free-TV)
        $streamType = match (true) {
            str_contains($contentType, 'mpegurl') => 'hls',
            str_contains($contentType, 'mp4') => 'mp4',
            str_contains($contentType, 'matroska') => 'mkv',
            str_ends_with($channel->url, '.m3u8') => 'hls',
            str_ends_with($channel->url, '.mp4') => 'mp4',
            default => 'unknown',
        };

        if (str_contains($contentType, 'text/html')) {
            return ['ok' => false, 'http_status' => $response->status(), 'content_type' => $contentType, 'ffprobe_ok' => null, 'message' => 'Content-Type text/html (provavelmente pagina, nao stream).'];
        }

        $ffprobeOk = $this->ffprobeCanOpen($channel->url, $headers);

        return [
            'ok' => $ffprobeOk,
            'http_status' => $response->status(),
            'content_type' => $contentType,
            'ffprobe_ok' => $ffprobeOk,
            'message' => $ffprobeOk ? null : 'ffprobe nao conseguiu abrir o stream.',
            'stream_type' => $streamType,
        ];
    }

    private function ffprobeCanOpen(string $url, array $headers): bool
    {
        $args = ['ffprobe', '-v', 'error', '-timeout', '6000000'];

        if (! empty($headers)) {
            $headerLines = collect($headers)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\r\n");
            $args[] = '-headers';
            $args[] = $headerLines . "\r\n";
        }

        $args = array_merge($args, ['-select_streams', 'v:0', '-show_entries', 'stream=codec_type', '-of', 'csv=p=0', $url]);

        $result = Process::timeout(8)->run($args);

        return $result->successful() && trim($result->output()) !== '';
    }
}
