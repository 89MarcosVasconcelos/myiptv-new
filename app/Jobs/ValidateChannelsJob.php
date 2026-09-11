<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelCheck;
use App\Models\ImportRun;
use App\Models\Playlist;
use App\Support\SafeUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Http\Client\Pool;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

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
 * Continua rodando com um unico "php artisan queue:work --queue=validation" — nao precisa abrir
 * mais terminal nenhum.
 *
 * Windows nao tem pcntl — o $timeout de job do Laravel (que mata o worker via
 * sinal) simplesmente NAO FUNCIONA aqui. Isso significa que qualquer chamada
 * bloqueante sem timeout proprio pode travar o worker inteiro PARA SEMPRE (o
 * "Zerar filas" nao resolve isso: ele limpa jobs na fila, mas o processo do
 * worker continua preso dentro do handle() de um job que nunca retorna). Por
 * isso o cuidado extra abaixo com o watchdog do ffprobe.
 */
class ValidateChannelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Quantos ffprobe rodando ao mesmo tempo, no maximo, por job. */
    private const FFPROBE_CONCURRENCY = 10;

    /** Segundos de parede antes de matar um ffprobe que nao terminou sozinho. */
    private const FFPROBE_WALL_TIMEOUT = 10;

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

        // Recalcula os contadores da playlist a partir do status REAL de cada
        // canal (refreshCounters), em vez de somar/subtrair incrementalmente
        // aqui. O incremental parecia certo mas desalinhava (percentuais
        // errados na tela) sempre que um canal era processado mais de uma vez
        // — o que aconteceu de verdade aqui: jobs presos/duplicados na fila
        // (o motivo de existir o botao "Zerar filas") faziam o mesmo canal
        // contar "ok" ou "falhou" duas vezes. Recontar do zero e mais caro
        // (3 COUNT) mas nunca desalinha, nao importa quantas vezes um job
        // rode de novo.
        if ($playlistId) {
            Playlist::find($playlistId)?->refreshCounters();
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
                FinalizeImportRunJob::dispatch($importRun->id, $playlistId ?? $importRun->playlist_id)->onQueue('validation');
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
        foreach ($this->runFfprobePool($needsFfprobe) as $channelId => $ffprobeResult) {
            $info = $needsFfprobe[$channelId];
            $ffprobeOk = $ffprobeResult['ok'];
            $results[$channelId] = [
                'ok' => $ffprobeOk,
                'http_status' => $info['http_status'],
                'content_type' => $info['content_type'],
                'ffprobe_ok' => $ffprobeOk,
                'message' => $ffprobeOk ? null : $this->ffprobeFailureMessage($ffprobeResult),
                'stream_type' => $info['stream_type'],
            ];
        }

        return $results;
    }

    /**
     * Roda ffprobe pra cada entrada em $needsFfprobe com no maximo
     * self::FFPROBE_CONCURRENCY processos simultaneos (janela deslizante).
     *
     * BUG CORRIGIDO AQUI: o Symfony Process so verifica o setTimeout() dentro
     * de checkTimeout() — chamar so isRunning() (como este loop fazia antes)
     * NUNCA dispara o watchdog, porque isRunning() nao chama checkTimeout()
     * internamente (so wait()/run() chamam). Ou seja: o "FFPROBE_WALL_TIMEOUT"
     * nunca era aplicado de verdade, e um ffprobe preso num stream ruim
     * (conexao que trava no meio, comum em fonte IPTV instavel) ficava
     * "isRunning() == true" pra sempre — travando esse job, e como so tem UM
     * worker (sem pcntl no Windows pra matar isso de fora), travava a fila
     * inteira ate alguem reiniciar o servico na mao. Alem de chamar
     * checkTimeout() explicitamente, tem um watchdog de lote (deadline) como
     * segunda camada de seguranca, caso algum caso nao previsto escape do
     * timeout individual do Symfony.
     *
     * @return array<int, array{ok: bool, timed_out: bool, exit_code: ?int, stderr: string}>
     */
    private function runFfprobePool(array $needsFfprobe): array
    {
        $queue = array_values($needsFfprobe);
        $running = []; // channel_id => Process
        $done = [];

        // Teto de tempo pro LOTE inteiro: tempo de 1 ffprobe (+ folga) vezes
        // quantas "ondas" de FFPROBE_CONCURRENCY cabem no lote, mais uma
        // margem generosa. Isso nunca deveria ser atingido (checkTimeout ja
        // deveria ter resolvido cada processo individualmente) — e so uma
        // rede de seguranca pra garantir que o job SEMPRE retorna.
        $waves = (int) ceil(count($needsFfprobe) / self::FFPROBE_CONCURRENCY);
        $deadline = microtime(true) + ($waves * (self::FFPROBE_WALL_TIMEOUT + 5)) + 30;

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
                $timedOut = false;

                try {
                    // checkTimeout() e quem de fato compara o relogio com o
                    // setTimeout() do processo e lanca a excecao quando
                    // estoura — isRunning() sozinho NUNCA faz essa checagem.
                    $process->checkTimeout();

                    if ($process->isRunning()) {
                        continue;
                    }
                } catch (ProcessTimedOutException $e) {
                    $timedOut = true;
                    $process->stop(1);
                }

                $done[$channelId] = [
                    'ok' => ! $timedOut && $process->isSuccessful() && trim($process->getOutput()) !== '',
                    'timed_out' => $timedOut,
                    'exit_code' => $timedOut ? null : $process->getExitCode(),
                    // mb_scrub: no Windows o cmd.exe costuma devolver o texto no
                    // codepage OEM (ex.: CP850), nao UTF-8 — sem isso, bytes
                    // invalidos quebram tanto o preg_match quanto o INSERT no
                    // MySQL (coluna utf8mb4 rejeita sequencia invalida).
                    'stderr' => mb_scrub(trim($process->getErrorOutput())),
                ];
                unset($running[$channelId]);
            }

            // Rede de seguranca: se por qualquer motivo ainda sobrou processo
            // rodando alem do prazo do lote inteiro, mata tudo na marra e
            // marca como timeout — nunca deixa o job (e o worker) preso.
            if (! empty($running) && microtime(true) > $deadline) {
                foreach ($running as $channelId => $process) {
                    $process->stop(1);
                    $done[$channelId] = [
                        'ok' => false,
                        'timed_out' => true,
                        'exit_code' => null,
                        'stderr' => 'watchdog: tempo maximo do lote de ffprobe excedido.',
                    ];
                }
                $running = [];
                $queue = [];
            }
        }

        return $done;
    }

    /**
     * Mensagem de erro registrada no ChannelCheck — inclui o stderr real do
     * ffprobe (truncado) em vez de um texto generico, pra dar pra diagnosticar
     * pela tela de log de erros sem precisar rodar o comando na mao.
     */
    private function ffprobeFailureMessage(array $ffprobeResult): string
    {
        if ($ffprobeResult['timed_out']) {
            return 'ffprobe excedeu o tempo limite (stream muito lento pra responder ou trava no meio).';
        }

        $stderr = $ffprobeResult['stderr'];

        // Padrao classico de "comando nao encontrado" (Windows CMD, PT ou EN,
        // e o "command not found" do shell POSIX) — quando isso aparece, o
        // ffprobe nunca chegou a rodar: nao e falha do stream, e do binario
        // nao estar no PATH do processo do worker. Mensagem clara em vez do
        // texto truncado do sistema operacional, e igual pra todo canal ate
        // o FFPROBE_PATH ser configurado.
        // Ancoras sem acento de proposito: o cmd.exe do Windows costuma
        // devolver essas mensagens no codepage OEM (ex.: CP850), nao UTF-8, e um
        // trecho acentuado no regex simplesmente nunca bateria com os bytes reais.
        if (preg_match('/reconhecido como um comando|arquivo em lotes|is not recognized as an internal or external command|command not found/i', $stderr)) {
            return 'ffprobe nao foi encontrado pelo worker (binario fora do PATH do processo). Configure FFPROBE_PATH no .env com o caminho completo do ffprobe e reinicie o "queue:work".';
        }

        if ($stderr === '') {
            return 'ffprobe nao conseguiu abrir o stream (sem saida de erro — verifique se o codec/protocolo e suportado).';
        }

        // Fica so a ultima linha util — geralmente é a que explica o motivo
        // real (ex.: "Server returned 403 Forbidden", "Connection refused",
        // "Invalid data found when processing input").
        $lines = array_values(array_filter(explode("\n", $stderr), fn ($l) => trim($l) !== ''));
        $lastLine = end($lines) ?: $stderr;

        return 'ffprobe: ' . mb_substr(trim($lastLine), 0, 300);
    }

    /** User-Agent default quando o canal nao trouxe um (#EXTVLCOPT) — varios
     *  paineis IPTV bloqueiam/truncam o UA padrao do ffmpeg ("Lavf/x.x") mas
     *  liberam o de players conhecidos como o VLC. */
    private const DEFAULT_USER_AGENT = 'VLC/3.0.20 LibVLC/3.0.20';

    private function buildFfprobeProcess(string $url, array $headers): Process
    {
        $args = [config('services.ffprobe.path', 'ffprobe'), '-v', 'error', '-timeout', '6000000'];

        $hasUserAgent = collect($headers)->keys()->contains(fn ($k) => strtolower($k) === 'user-agent');
        if (! $hasUserAgent) {
            $args[] = '-user_agent';
            $args[] = self::DEFAULT_USER_AGENT;
        }

        if (! empty($headers)) {
            $headerLines = collect($headers)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\r\n");
            $args[] = '-headers';
            $args[] = $headerLines . "\r\n";
        }

        $args = array_merge($args, ['-select_streams', 'v:0', '-show_entries', 'stream=codec_type', '-of', 'csv=p=0', $url]);

        $process = new Process($args);
        $process->setTimeout(self::FFPROBE_WALL_TIMEOUT + 2);

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
