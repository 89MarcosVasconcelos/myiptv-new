<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ImportCsvJob;
use App\Jobs\ImportPlaylistJob;
use App\Jobs\ValidateChannelsJob;
use App\Models\ImportRun;
use App\Models\Playlist;
use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PlaylistController extends Controller
{
    /** Segundos que um job pode ficar "reservado" (um worker pegou pra processar)
     *  antes de considerarmos que o worker travou nele. Bem acima do pior caso
     *  legitimo (lote de 50 canais, ffprobe com teto de ~15s cada, 10 em
     *  paralelo — uns 2-3min no maximo), com folga de sobra. */
    private const STUCK_JOB_THRESHOLD_SECONDS = 300;

    /** GET /api/v1/playlists/{playlist} — status pontual, usado pelo polling da tela de cadastro enquanto o import roda */
    public function show(Playlist $playlist)
    {
        return response()->json($playlist);
    }

    /** GET /api/v1/playlists?q=&page= — usado pelo select com busca */
    public function index(Request $request)
    {
        $playlists = Playlist::query()
            ->when($request->q, fn ($q) => $q->where('name', 'like', "%{$request->q}%"))
            ->latest()
            ->paginate(20);

        // Contadores incrementais (ok_count/failed_count/pending_count) podem
        // sair da realidade quando um job de validacao roda mais de uma vez
        // pro mesmo canal (retry, worker que travou e reiniciou no meio,
        // etc.) — foi isso que causou os "149%"/"147%" na tela de listas.
        // Como sao poucas listas, recalcular do zero (COUNT real por status)
        // toda vez que a tela carrega e barato e garante que a porcentagem
        // mostrada NUNCA fica errada, sem depender de lembrar de "zerar fila".
        $playlists->getCollection()->each(function (Playlist $p) {
            $p->refreshCounters();

            // Autocorrige o caso em que a validacao ja terminou de verdade
            // (pending_count chegou a 0) mas o FinalizeImportRunJob que devia
            // virar o status pra "ok"/"failed" nao rodou — por exemplo, se
            // ficou preso na mesma janela de travamento do worker que
            // motivou esse ajuste. Sem isso a lista fica presa em
            // "processing" pra sempre e nunca mais oferece o botao de
            // reprocessar. So mexe quando pending_count e mesmo 0 (nao
            // interfere com uma validacao de fato em andamento).
            if ($p->status === 'processing' && $p->total_count > 0 && $p->pending_count === 0) {
                $p->update(['status' => $p->failed_count > 0 && $p->ok_count === 0 ? 'failed' : 'ok']);
            }
        });

        return response()->json($playlists);
    }

    /**
     * POST /api/v1/playlists
     * Dois caminhos bem diferentes em custo:
     * - textarea: poucos links colados a mao, cada um vira uma "lista" que o
     *   ImportPlaylistJob baixa e inspeciona (pode ser M3U pra expandir, ou
     *   midia direta) — cabe fazer isso sincronamente aqui, sao poucos.
     * - CSV: pode vir com dezenas de milhares de linhas, e cada linha ja e um
     *   canal conhecido (url + nome, sem precisar baixar nada pra descobrir o
     *   tipo). Criar isso um a um aqui estourava os 60s de execucao do PHP —
     *   agora so guardamos o arquivo e devolvemos na hora; quem insere em
     *   lote e enfileira a validacao e o ImportCsvJob, rodando no worker.
     */
    public function store(Request $request)
    {
        $created = [];

        if ($request->filled('urls')) {
            $links = collect(explode(',', $request->string('urls')))
                ->map(fn ($u) => trim($u))
                ->filter()
                ->values();

            foreach ($links as $url) {
                if (! filter_var($url, FILTER_VALIDATE_URL)) {
                    continue;
                }

                $source = Source::create([
                    'type' => 'url',
                    'reference' => $url,
                    'user_id' => $request->user()?->id,
                ]);

                $playlist = Playlist::create([
                    'source_id' => $source->id,
                    'url' => $url,
                    'status' => 'pending',
                ]);

                ImportPlaylistJob::dispatch($playlist)->onQueue('imports');
                $created[] = $playlist;
            }
        }

        if ($request->hasFile('csv')) {
            $file = $request->file('csv');
            $storagePath = $file->store('imports');

            $source = Source::create([
                'type' => 'csv',
                'reference' => $file->getClientOriginalName(),
                'import_path' => $storagePath,
                'user_id' => $request->user()?->id,
            ]);

            $playlist = Playlist::create([
                'source_id' => $source->id,
                'name' => $file->getClientOriginalName(),
                'status' => 'pending',
            ]);

            ImportCsvJob::dispatch($playlist->id, $storagePath)->onQueue('imports');
            $created[] = $playlist;
        }

        if (empty($created)) {
            return response()->json(['message' => 'Nenhum link ou arquivo valido informado.'], 422);
        }

        return response()->json(['created' => count($created), 'playlists' => $created], 202);
    }

    /**
     * POST /api/v1/playlists/{playlist}/validate
     * Botao manual "Iniciar verificacao" / "Reprocessar" — importar nunca
     * dispara isso sozinho. Valida (probe HTTP + ffprobe) todo canal que
     * ainda nao esta "ok": pending (primeira vez) e failed/dead (reavaliar).
     */
    public function validate(Playlist $playlist)
    {
        $channelIds = $playlist->channels()
            ->whereIn('status', ['pending', 'failed', 'dead'])
            ->pluck('id');

        if ($channelIds->isEmpty()) {
            return response()->json(['message' => 'Nao ha canais pendentes ou com falha para verificar.'], 422);
        }

        $importRun = ImportRun::create([
            'playlist_id' => $playlist->id,
            'status' => 'processing',
            'total' => $channelIds->count(),
        ]);

        $playlist->update(['status' => 'processing']);

        $channelIds->chunk(50)->each(
            fn ($chunk) => ValidateChannelsJob::dispatch($chunk->values()->all(), $importRun->id)->onQueue('validation')
        );

        return response()->json(['status' => 'queued', 'total' => $channelIds->count()]);
    }

    /**
     * POST /api/v1/playlists/{playlist}/cancel
     * "Cancelar verificacao" — usado quando uma lista fica presa em
     * "processing" (ex.: worker parado no meio, ou um import_run orfao de
     * uma execucao antiga). So mexe em status/import_runs, nunca apaga
     * canais ja verificados; depois disso o botao de iniciar/reprocessar
     * volta a aparecer normalmente.
     */
    public function cancel(Playlist $playlist)
    {
        $playlist->importRuns()
            ->where('status', 'processing')
            ->update(['status' => 'failed', 'error_message' => 'Cancelado manualmente pelo usuario.']);

        $this->recomputeStatus($playlist);

        return response()->json(['status' => 'cancelled']);
    }

    /**
     * POST /api/v1/playlists/{playlist}/reimport
     * Pra quando a IMPORTACAO em si (nao a verificacao) travou ou falhou e a
     * lista nunca chegou a ganhar nenhum canal (total_count = 0) — o botao
     * "Iniciar verificacao" nem aparece nesse caso, porque nao ha o que
     * verificar ainda. Reenfileira o job de origem certo (CSV ou URL) do
     * zero. Para CSV, usa o arquivo original guardado em sources.import_path
     * — se ele ja tiver sido limpo (import concluido antes, ou removido
     * manualmente), pede pra reenviar em vez de falhar silenciosamente.
     */
    public function reimport(Playlist $playlist)
    {
        if ($playlist->total_count > 0) {
            return response()->json(['message' => 'Essa lista ja tem canais importados — use "Iniciar verificacao" ou "Reprocessar".'], 422);
        }

        $source = $playlist->source;

        if ($source && $source->type === 'csv') {
            if (! $source->import_path || ! Storage::exists($source->import_path)) {
                return response()->json([
                    'message' => 'O arquivo CSV original nao esta mais disponivel. Remova esta lista e reenvie o arquivo.',
                ], 422);
            }

            $playlist->update(['status' => 'processing', 'error_message' => null]);
            ImportCsvJob::dispatch($playlist->id, $source->import_path)->onQueue('imports');

            return response()->json(['status' => 'queued']);
        }

        if ($playlist->url) {
            $playlist->update(['status' => 'pending', 'error_message' => null]);
            ImportPlaylistJob::dispatch($playlist)->onQueue('imports');

            return response()->json(['status' => 'queued']);
        }

        return response()->json(['message' => 'Nao foi possivel identificar a origem desta lista para reimportar.'], 422);
    }

    /**
     * POST /api/v1/playlists/reset-queue
     * "Zerar filas": botao de emergencia pra destravar tudo sem precisar de
     * terminal. Limpa jobs presos/duplicados da fila "validation" (a causa
     * mais comum de lista que "para de carregar" ou "nao inicia") e os
     * failed_jobs acumulados, e destrava qualquer lista em "processing" cujo
     * import_run ficou orfao — depois disso os botoes de iniciar/reprocessar/
     * reimportar voltam a aparecer normalmente pra cada lista.
     *
     * IMPORTANTE (limite real desse botao): isso limpa JOBS NA FILA. Se o
     * worker em si ja estiver travado dentro do handle() de um job que nunca
     * retorna (o cenario que motivou o watchdog do ffprobe em
     * ValidateChannelsJob), limpar a fila NAO acorda esse processo — ele
     * continua preso, e nenhum job novo (nem os que este botao acabou de
     * "liberar") vai ser pego ate o servico ser reiniciado
     * (Restart-Service IptvQueueWorker). Por isso devolvemos tambem o
     * diagnostico de "travado" — pra tela avisar isso explicitamente em vez
     * de dar a entender que zerar sozinho sempre resolve.
     */
    public function resetQueue()
    {
        $wasStuck = $this->stuckWorkerInfo()['stuck'];

        $jobsCleared = DB::table('jobs')->where('queue', 'validation')->count();
        DB::table('jobs')->where('queue', 'validation')->delete();

        $failedCleared = DB::table('failed_jobs')->count();
        DB::table('failed_jobs')->truncate();

        $unstuck = 0;

        Playlist::where('status', 'processing')->get()->each(function (Playlist $playlist) use (&$unstuck) {
            $playlist->importRuns()
                ->where('status', 'processing')
                ->update(['status' => 'failed', 'error_message' => 'Fila zerada manualmente.']);

            $this->recomputeStatus($playlist);
            $unstuck++;
        });

        return response()->json([
            'jobs_cleared' => $jobsCleared,
            'failed_jobs_cleared' => $failedCleared,
            'playlists_unstuck' => $unstuck,
            'worker_was_stuck' => $wasStuck,
        ]);
    }

    /**
     * GET /api/v1/queue/health
     * Diagnostico pra tela avisar SOZINHA quando o worker parece travado —
     * antes disso, o unico jeito de perceber era uma lista ficar parada por
     * horas e so descobrir olhando o log manualmente. "Travado" aqui = existe
     * um job "reservado" (um worker pegou pra processar) ha mais tempo do que
     * qualquer processamento legitimo levaria.
     */
    public function queueHealth()
    {
        return response()->json($this->stuckWorkerInfo());
    }

    /**
     * GET /api/v1/queue/jobs
     * Conteudo real da fila, pra tela "Fila de processamento". Antes disso o
     * unico jeito de saber o que estava enfileirado (e por que uma lista nao
     * andava) era abrir o banco ou o log na mao. Mostra o que esta esperando,
     * o que esta sendo processado agora, e o que ja falhou de vez.
     *
     * A tabela 'jobs' guarda a classe do job dentro do payload JSON
     * (displayName), por isso a leitura e feita decodificando o payload em
     * vez de existir uma coluna pronta.
     */
    public function queueJobs(Request $request)
    {
        $limit = min(max((int) $request->input('limit', 50), 1), 200);

        $pending = DB::table('jobs')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true) ?: [];

                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'job' => class_basename($payload['displayName'] ?? 'desconhecido'),
                    'attempts' => $job->attempts,
                    // reserved_at preenchido = um worker pegou esse job e esta
                    // processando agora. E o unico que realmente ocupa o worker;
                    // todos os outros so estao esperando a vez.
                    'running' => ! is_null($job->reserved_at),
                    'running_for_seconds' => $job->reserved_at ? time() - (int) $job->reserved_at : null,
                    'waiting_for_seconds' => time() - (int) $job->available_at,
                ];
            });

        // Quantos de cada tipo tem no total (nao so na pagina mostrada) — e o
        // que responde "por que minha lista nao importa": normalmente e um
        // ImportCsvJob esperando atras de centenas de ValidateChannelsJob.
        $byType = DB::table('jobs')
            ->get(['payload', 'queue'])
            ->groupBy(function ($job) {
                $payload = json_decode($job->payload, true) ?: [];

                return class_basename($payload['displayName'] ?? 'desconhecido');
            })
            ->map(fn ($group) => $group->count())
            ->sortDesc();

        $failed = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true) ?: [];

                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'job' => class_basename($payload['displayName'] ?? 'desconhecido'),
                    'failed_at' => $job->failed_at,
                    // So a primeira linha: o stack trace inteiro e gigante e
                    // inutil na tela — a mensagem de topo ja diz o motivo.
                    'error' => \Illuminate\Support\Str::limit(strtok($job->exception, "\n"), 300),
                ];
            });

        return response()->json([
            'health' => $this->stuckWorkerInfo(),
            'total_pending' => DB::table('jobs')->count(),
            'total_failed' => DB::table('failed_jobs')->count(),
            'by_type' => $byType,
            'jobs' => $pending,
            'failed' => $failed,
        ]);
    }

    /**
     * Cobre as duas filas ('validation' e 'enrichment', esta ultima separada
     * justamente pra um catalogo grande de enriquecimento nunca mais poder
     * monopolizar o worker) — um job preso em qualquer uma das duas conta
     * como worker travado.
     */
    private function stuckWorkerInfo(): array
    {
        $oldestReservedAt = DB::table('jobs')
            ->whereIn('queue', ['imports', 'validation', 'enrichment'])
            ->whereNotNull('reserved_at')
            ->min('reserved_at');

        $stuckForSeconds = $oldestReservedAt ? (time() - (int) $oldestReservedAt) : null;
        $stuck = $stuckForSeconds !== null && $stuckForSeconds > self::STUCK_JOB_THRESHOLD_SECONDS;

        return [
            'queued_jobs' => DB::table('jobs')->where('queue', 'validation')->count(),
            'import_queued_jobs' => DB::table('jobs')->where('queue', 'imports')->count(),
            'enrichment_queued_jobs' => DB::table('jobs')->where('queue', 'enrichment')->count(),
            'stuck' => $stuck,
            'stuck_for_seconds' => $stuck ? $stuckForSeconds : null,
        ];
    }

    /** Recalcula o status da playlist a partir dos contadores reais de canais. Usado por cancel() e resetQueue(). */
    private function recomputeStatus(Playlist $playlist): void
    {
        $playlist->refreshCounters();

        if ($playlist->total_count === 0) {
            // Import nunca chegou a gerar nenhum canal — nao e "ok" nem
            // "failed" de verdade, e o caso que precisa do botao "Reiniciar
            // importacao" (ver isStuckImport() no front).
            $playlist->update(['status' => 'pending']);
        } elseif ($playlist->failed_count > 0 && $playlist->ok_count === 0 && $playlist->pending_count === 0) {
            $playlist->update(['status' => 'failed']);
        } elseif ($playlist->pending_count > 0 || $playlist->failed_count > 0) {
            $playlist->update(['status' => 'pending']);
        } else {
            $playlist->update(['status' => 'ok']);
        }
    }

    /**
     * DELETE /api/v1/playlists/{playlist}
     * Remove a lista e tudo que depende dela (canais, checks, import_runs e
     * a source associada). Feito numa transacao pra nao deixar lixo se algo
     * falhar no meio.
     */
    public function destroy(Playlist $playlist)
    {
        DB::transaction(function () use ($playlist) {
            $channelIds = $playlist->channels()->pluck('id');

            if ($channelIds->isNotEmpty()) {
                DB::table('channel_checks')->whereIn('channel_id', $channelIds)->delete();
            }

            $playlist->channels()->delete();
            $playlist->importRuns()->delete();

            $sourceId = $playlist->source_id;
            $playlist->delete();

            if ($sourceId) {
                Source::where('id', $sourceId)->delete();
            }
        });

        return response()->json(['status' => 'deleted']);
    }

    /**
     * GET /api/v1/playlists/{playlist}/errors
     * Log de erros: canais com falha/mortos dessa lista, com o motivo da
     * ultima checagem (http_status, content-type detectado, saida do
     * ffprobe) pra o usuario decidir se remove a URL da fonte original.
     */
    public function errors(Playlist $playlist, Request $request)
    {
        $channels = $playlist->channels()
            ->whereIn('status', ['failed', 'dead'])
            ->with(['checks' => fn ($q) => $q->latest('checked_at')->limit(1)])
            ->when($request->filled('q'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->q . '%')
                    ->orWhere('url', 'like', '%' . $request->q . '%');
            }))
            ->orderBy('name')
            ->paginate(50);

        return response()->json($channels);
    }
}
