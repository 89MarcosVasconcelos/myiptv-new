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

                ImportPlaylistJob::dispatch($playlist)->onQueue('validation');
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

            ImportCsvJob::dispatch($playlist->id, $storagePath)->onQueue('validation');
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
            ImportCsvJob::dispatch($playlist->id, $source->import_path)->onQueue('validation');

            return response()->json(['status' => 'queued']);
        }

        if ($playlist->url) {
            $playlist->update(['status' => 'pending', 'error_message' => null]);
            ImportPlaylistJob::dispatch($playlist)->onQueue('validation');

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
     */
    public function resetQueue()
    {
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
        ]);
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
