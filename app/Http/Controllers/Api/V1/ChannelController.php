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

        foreach (['country_id', 'mode_id', 'content_type_id', 'genre_id', 'language_id', 'subtitle_id'] as $filter) {
            if ($request->filled($filter)) {
                $base->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('q')) {
            $base->where('name', 'like', '%' . $request->q . '%');
        }

        $channels = (clone $base)->with(['country', 'mode', 'contentType', 'genre', 'language', 'subtitle'])
            ->orderByRaw('channel_number is null, channel_number asc')
            ->orderBy('name')
            ->paginate(30);

        $facets = [
            'countries' => (clone $base)->whereNotNull('country_id')->distinct()->pluck('country_id'),
            'modes' => (clone $base)->whereNotNull('mode_id')->distinct()->pluck('mode_id'),
            'content_types' => (clone $base)->whereNotNull('content_type_id')->distinct()->pluck('content_type_id'),
            'genres' => (clone $base)->whereNotNull('genre_id')->distinct()->pluck('genre_id'),
        ];

        return response()->json(['channels' => $channels, 'facets' => $facets]);
    }

    /** PATCH /api/v1/channels/{channel} — edicao inline na tela de itens */
    public function update(Request $request, Channel $channel)
    {
        $data = $request->validate([
            'country_id' => 'nullable|exists:countries,id',
            'mode_id' => 'nullable|exists:modes,id',
            'content_type_id' => 'nullable|exists:content_types,id',
            'genre_id' => 'nullable|exists:genres,id',
            'language_id' => 'nullable|exists:languages,id',
            'subtitle_id' => 'nullable|exists:subtitles,id',
            'name' => 'sometimes|string|max:255',
        ]);

        $channel->update($data);

        return response()->json($channel);
    }

    /** POST /api/v1/channels/{channel}/recheck — reprocessamento por item */
    public function recheck(Channel $channel)
    {
        ValidateChannelsJob::dispatch([$channel->id], $channel->playlist->importRuns()->latest()->value('id') ?? 0)
            ->onQueue('validation');

        return response()->json(['status' => 'queued']);
    }
}
