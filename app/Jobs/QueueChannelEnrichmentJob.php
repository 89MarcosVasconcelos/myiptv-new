<?php

namespace App\Jobs;

use App\Models\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * O botao "Preencher lacunas" pode precisar enfileirar milhares de canais de
 * uma vez — fazer esse loop de dispatch() dentro da propria requisicao HTTP
 * travava a tela por completo (era exatamente o "apertei e nada mudou": o
 * PHP ficava minutos inserindo um EnrichChannelJob por canal antes de
 * conseguir responder). Este job faz esse trabalho pesado em background: o
 * controller so dispara ESTE job (instantaneo) e responde na hora.
 *
 * Fila propria ('enrichment', nao 'validation'): um catalogo grande (aqui,
 * ~11 mil canais de uma vez) enfileirava tudo isso junto com a verificacao
 * de listas, e como so existe UM worker no Windows, milhares de jobs de
 * enriquecimento monopolizavam esse worker por horas — travando a validacao
 * de listas de verdade (isso aconteceu, foi o motivo real da tela "Listas
 * carregadas" parecer travada mesmo sem nenhum job realmente preso). Fila
 * separada garante que um catalogo gigante de enriquecimento nunca mais
 * impede a validacao de listas de andar.
 */
class QueueChannelEnrichmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    /**
     * @param  bool  $force  false = so os canais com tipo/genero/descricao
     *                       vazios ("parcial"); true = todos os canais
     *                       ("total" — o ChannelEnricher continua nunca
     *                       sobrescrevendo campo ja preenchido, entao isso e
     *                       so pra tentar de novo canais que falharam antes).
     */
    public function __construct(private readonly bool $force = false)
    {
    }

    public function handle(): void
    {
        $query = Channel::query();

        if (! $this->force) {
            $query->where(function ($q) {
                $q->whereNull('content_type_id')
                    ->orWhereNull('description')
                    ->orWhereDoesntHave('genres');
            });
        }

        $force = $this->force;

        $query->select('id')
            ->chunkById(500, function ($channels) use ($force) {
                foreach ($channels as $channel) {
                    EnrichChannelJob::dispatch($channel->id, $force)->onQueue('enrichment');
                }
            });
    }
}
