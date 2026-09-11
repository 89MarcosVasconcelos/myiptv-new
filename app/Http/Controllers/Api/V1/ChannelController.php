<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\QueueChannelEnrichmentJob;
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

    /**
     * POST /api/v1/channels/fill-gaps
     * Botao "Preencher lacunas". Com catalogo grande (aqui, ~11 mil canais
     * sem tipo/genero/descricao de uma vez), enfileirar um EnrichChannelJob
     * por canal DENTRO da propria requisicao travava a tela por minutos —
     * era o "apertei e nao aconteceu nada": o PHP ficava inserindo milhares
     * de linhas antes de conseguir responder. Agora so dispara o
     * QueueChannelEnrichmentJob (instantaneo) que faz esse trabalho pesado
     * em background, e devolve na hora uma contagem aproximada pro usuario
     * ver que tem coisa pra fazer.
     *
     * Fila 'enrichment', separada de 'validation': um catalogo gigante de
     * enriquecimento nao pode nunca mais monopolizar o worker e travar a
     * validacao de listas (foi exatamente isso que aconteceu antes desse
     * ajuste).
     *
     * Dois modos (?mode=partial|total):
     * - partial (padrao): so os canais que ainda tem tipo, genero ou
     *   descricao vazios — o "preencher o que falta" de sempre.
     * - total: TODOS os canais, mesmo os que ja tem tudo preenchido. Util
     *   depois de configurar uma chave de API nova ou corrigir o certificado
     *   SSL, pra tentar de novo os canais que so falharam por causa disso.
     *   O ChannelEnricher continua nunca sobrescrevendo um campo que ja tem
     *   valor, entao rodar "total" num canal ja completo simplesmente nao
     *   faz nada nele — sem risco de perder dado bom.
     *
     * O total do lote fica guardado em cache pra tela conseguir montar a
     * barra de progresso (ver enrichmentProgress()) comparando com quanto
     * ainda resta na fila 'enrichment' — sem precisar de tabela nova nem de
     * contador incremental (que ja vimos, na fila de validacao, que desalinha
     * com retry/worker reiniciado no meio).
     */
    public function fillGaps(Request $request)
    {
        $mode = $request->input('mode') === 'total' ? 'total' : 'partial';
        $force = $mode === 'total';

        $query = Channel::query();

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('content_type_id')
                    ->orWhereNull('description')
                    ->orWhereDoesntHave('genres');
            });
        }

        $count = $query->count();

        \Illuminate\Support\Facades\Cache::put('enrichment_batch', [
            'total' => $count,
            'mode' => $mode,
            'started_at' => now()->toIso8601String(),
        ], now()->addDays(2));

        QueueChannelEnrichmentJob::dispatch($force)->onQueue('enrichment');

        return response()->json(['queued' => $count, 'mode' => $mode]);
    }

    /**
     * GET /api/v1/channels/enrichment-progress
     * Progresso do lote de enriquecimento mais recente, pra tela mostrar uma
     * barra de carregamento. "Processado" e sempre TOTAL menos o que ainda
     * esta na fila 'enrichment' — nunca um contador incremental (mesmo
     * motivo do refreshCounters() das listas: sobrevive a job que roda de
     * novo, worker que reinicia no meio, etc., porque sempre reflete o
     * estado real da fila em vez de somar eventos).
     */
    public function enrichmentProgress()
    {
        $batch = \Illuminate\Support\Facades\Cache::get('enrichment_batch');

        if (! $batch) {
            return response()->json(['running' => false, 'total' => 0]);
        }

        $remaining = \Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'enrichment')->count();
        $total = (int) $batch['total'];
        $processed = max(0, $total - $remaining);
        $percent = $total > 0 ? (int) round($processed / $total * 100) : 100;

        return response()->json([
            'running' => $remaining > 0,
            'mode' => $batch['mode'] ?? 'partial',
            'total' => $total,
            'processed' => $processed,
            'remaining' => $remaining,
            'percent' => $percent,
            'started_at' => $batch['started_at'] ?? null,
        ]);
    }

    /**
     * POST /api/v1/channels/cancel-fill-gaps
     * Limpa qualquer job de enriquecimento (EnrichChannelJob ou o proprio
     * QueueChannelEnrichmentJob) ainda pendente na fila — tanto os que ja
     * foram enfileirados na fila nova 'enrichment' quanto os que sobraram
     * na fila 'validation' de antes dessa separacao existir. Util pra
     * cancelar um "Preencher lacunas" dado sem querer, ou parar um lote
     * grande enquanto as chaves de API/certificado SSL ainda nao foram
     * configurados (sem isso, os jobs so vao falhar mesmo).
     */
    public function cancelFillGaps()
    {
        $cleared = \Illuminate\Support\Facades\DB::table('jobs')
            ->where(function ($q) {
                $q->where('queue', 'enrichment')
                    ->orWhere('payload', 'like', '%EnrichChannelJob%')
                    ->orWhere('payload', 'like', '%QueueChannelEnrichmentJob%');
            })
            ->delete();

        \Illuminate\Support\Facades\Cache::forget('enrichment_batch');

        return response()->json(['jobs_cleared' => $cleared]);
    }
}
