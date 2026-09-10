<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ImportPlaylistJob;
use App\Models\Playlist;
use App\Models\Source;
use Illuminate\Http\Request;

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
     * Aceita import em lote: um ou mais links (M3U/M3U8 ou midia direta), ou um
     * upload de CSV ';' com colunas url;nome;pais;modo;tipo;genero;idioma;legenda.
     */
    public function store(Request $request)
    {
        $links = [];

        if ($request->filled('urls')) {
            // textarea: links separados por virgula
            $links = collect(explode(',', $request->string('urls')))
                ->map(fn ($u) => trim($u))
                ->filter()
                ->values()
                ->all();
        }

        if ($request->hasFile('csv')) {
            $links = array_merge($links, $this->parseCsv($request->file('csv')->getRealPath()));
        }

        if (empty($links)) {
            return response()->json(['message' => 'Nenhum link valido informado.'], 422);
        }

        $created = [];
        foreach ($links as $link) {
            $url = is_array($link) ? ($link['url'] ?? null) : $link;
            if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $source = Source::create([
                'type' => $request->hasFile('csv') ? 'csv' : 'url',
                'reference' => $url,
                'user_id' => $request->user()?->id,
            ]);

            $playlist = Playlist::create([
                'source_id' => $source->id,
                'name' => is_array($link) ? ($link['nome'] ?? null) : null,
                'url' => $url,
                'status' => 'pending',
            ]);

            ImportPlaylistJob::dispatch($playlist);
            $created[] = $playlist;
        }

        return response()->json(['created' => count($created), 'playlists' => $created], 202);
    }

    /**
     * Le o CSV ';' linha a linha (sem carregar tudo em memoria) e devolve um mapa
     * por linha: url, nome, pais, modo, tipo, genero, idioma, legenda (colunas
     * de metadado sao opcionais — quando vazias, o item fica pendente de edicao
     * manual na tela de itens).
     */
    private function parseCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        $header = null;

        while (($row = fgetcsv($handle, 4096, ';')) !== false) {
            if ($header === null) {
                $header = array_map(fn ($h) => strtolower(trim($h)), $row);
                continue;
            }
            if (empty($row[0])) {
                continue;
            }
            $rows[] = array_combine(array_slice($header, 0, count($row)), $row);
        }

        fclose($handle);

        return $rows;
    }

    /** POST /api/v1/playlists/{playlist}/recheck — botao "reavaliar" da lista inteira */
    public function recheck(Playlist $playlist)
    {
        $playlist->update(['status' => 'pending']);
        ImportPlaylistJob::dispatch($playlist);

        return response()->json(['status' => 'queued']);
    }
}
