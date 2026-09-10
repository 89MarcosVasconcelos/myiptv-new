<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ValidateChannelsJob;
use App\Models\Channel;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    /**
     * GET /api/v1/channels
     * Endpoint "faceted": devolve os canais que batem com os filtros e, junto,
     * as opcoes que ainda fazem sentido para os proximos selects encadeados
     * (pais -> modo -> tipo -> genero), pra tela do player nunca oferecer uma
     * combinacao sem resultado.
     */
    public function index(Request $request)
    {
        $base = Channel::query()->where('status', 'ok');

        foreach (['country_id', 'mode_id', 'content_type_id', 'language_id', 'subtitle_id'] as $filter) {
            if ($request->filled($filter)) {
                $base->where($filter, $request->input($filter));
            }
        }

        // genero e N-pra-N agora: "tem esse genero entre os que foram atribuidos".
        if ($request->filled('genre_id')) {
            $base->whereHas('genres', fn ($q) => $q->where('genres.id', $request->input('genre_id')));
        }

        if ($request->filled('q')) {
            $base->where('name', 'like', '%' . $request->q . '%');
        }

        $channels = (clone $base)->with(['country', 'mode', 'contentType', 'genres', 'language', 'subtitle'])
            ->orderByRaw('channel_number is null, channel_number asc')
            ->orderBy('name')
            ->paginate(30);

        $facets = [
            'countries' => (clone $base)->whereNotNull('country_id')->distinct()->pluck('country_id'),
            'modes' => (clone $base)->whereNotNull('mode_id')->distinct()->pluck('mode_id'),
            'content_types' => (clone $base)->whereNotNull('content_type_id')->distinct()->pluck('content_type_id'),
            'genres' => (clone $base)->join('channel_genre', 'channel_genre.channel_id', '=', 'channels.id')
                ->distinct()->pluck('channel_genre.genre_id'),
        ];

        return response()->json(['channels' => $channels, 'facets' => $facets]);
    }

    /**
     * GET /api/v1/channels/admin
     * Usado pela tela "Itens carregados" (Channels/Index.vue). Diferente do
     * index() acima (que so mostra status=ok pro Player publico), aqui
     * precisamos ver TODOS os itens — pendente, ok, falha, morto — porque e
     * exatamente onde o usuario classifica manualmente e reprocessa falhas.
     * Suporta busca por nome, ordenacao por qualquer coluna (inclusive pelas
     * taxonomias relacionadas, via join) e paginacao real.
     */
    public function adminIndex(Request $request)
    {
        // "genre" nao da mais pra ordenar com leftJoin direto (canal tem N
        // generos agora, o join duplicaria linhas e quebraria a paginacao).
        // Em vez disso, uma subquery escalar concatena os nomes por canal —
        // um valor so por linha, sem duplicar nada — e essa mesma coluna
        // (genre_names) serve tanto pra ordenar quanto so de apoio.
        $sortable = [
            'name' => 'channels.name',
            'status' => 'channels.status',
            'country' => 'countries.name',
            'mode' => 'modes.name',
            'content_type' => 'content_types.name',
            'genre' => 'genre_names',
            'language' => 'languages.name',
            'subtitle' => 'subtitles.name',
        ];

        $sortColumn = $sortable[$request->input('sort')] ?? 'channels.name';
        $direction = $request->input('dir') === 'desc' ? 'desc' : 'asc';
        $perPage = min(max((int) $request->input('per_page', 50), 10), 200);

        $query = Channel::query()
            ->select('channels.*')
            ->selectRaw(
                '(SELECT GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ", ") '
                . 'FROM channel_genre cg JOIN genres g ON g.id = cg.genre_id '
                . 'WHERE cg.channel_id = channels.id) as genre_names'
            )
            ->leftJoin('countries', 'countries.id', '=', 'channels.country_id')
            ->leftJoin('modes', 'modes.id', '=', 'channels.mode_id')
            ->leftJoin('content_types', 'content_types.id', '=', 'channels.content_type_id')
            ->leftJoin('languages', 'languages.id', '=', 'channels.language_id')
            ->leftJoin('subtitles', 'subtitles.id', '=', 'channels.subtitle_id')
            ->with(['country', 'mode', 'contentType', 'genres', 'language', 'subtitle']);

        if ($request->filled('q')) {
            $query->where('channels.name', 'like', '%' . $request->q . '%');
        }

        // "Lacunas" (pra alimentar o botao "Preencher lacunas" com um filtro
        // rapido na propria tela): tipo, genero ou descricao vazios.
        if ($request->boolean('missing_metadata')) {
            $query->where(function ($q) {
                $q->whereNull('channels.content_type_id')
                    ->orWhereNull('channels.description')
                    ->orWhereDoesntHave('genres');
            });
        }

        $channels = $query->orderBy($sortColumn, $direction)
            ->orderBy('channels.id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json($channels);
    }

    /** PATCH /api/v1/channels/{channel} — edicao inline na tela de itens */
    public function update(Request $request, Channel $channel)
    {
        $data = $request->validate([
            'country_id' => 'nullable|exists:countries,id',
            'mode_id' => 'nullable|exists:modes,id',
            'content_type_id' => 'nullable|exists:content_types,id',
            'genre_ids' => 'sometimes|array',
            'genre_ids.*' => 'integer|exists:genres,id',
            'language_id' => 'nullable|exists:languages,id',
            'subtitle_id' => 'nullable|exists:subtitles,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
        ]);

        $genreIds = $data['genre_ids'] ?? null;
        unset($data['genre_ids']);

        $channel->update($data);

        if ($genreIds !== null) {
            $channel->genres()->sync($genreIds);
        }

        return response()->json($channel->load('genres'));
    }

    /** POST /api/v1/channels/{channel}/recheck — reprocessamento por item */
    public function recheck(Channel $channel)
    {
        ValidateChannelsJob::dispatch([$channel->id], $channel->playlist->importRuns()->latest()->value('id') ?? 0)->onQueue('validation');

        return response()->json(['status' => 'queued']);
    }
}
