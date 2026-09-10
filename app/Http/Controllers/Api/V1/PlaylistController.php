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
use Illuminate\Support\Facades\Storage;

class PlaylistController extends Controller
{
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

                ImportPlaylistJob::dispatch($playlist);
                $created[] = $playlist;
            }
        }

        if ($request->hasFile('csv')) {
            $file = $request->file('csv');
            $storagePath = $file->store('imports');

            $source = Source::create([
                'type' => 'csv',
                'reference' => $file->getClientOriginalName(),
                'user_id' => $request->user()?->id,
            ]);

            $playlist = Playlist::create([
                'source_id' => $source->id,
                'name' => $file->getClientOriginalName(),
                'status' => 'pending',
            ]);

            ImportCsvJob::dispatch($playlist->id, $storagePath);
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
}
