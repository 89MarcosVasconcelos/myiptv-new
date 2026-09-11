<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\Enrichment\ChannelEnricher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Um canal por job — mais facil de retomar (se um canal falhar/der timeout
 * numa API externa, so ele reprocessa, o resto da fila segue normal) e evita
 * segurar o worker rodando um lote gigante numa unica execucao. Roda na fila
 * propria 'enrichment' (separada de 'validation'), pra um catalogo grande
 * nunca mais monopolizar o worker que processa as listas.
 *
 * tries=1: as fontes externas (ChannelEnricher) ja tem seu proprio try/catch
 * por fonte, entao uma falha aqui e sempre um erro de verdade (nao rede
 * passageira) — repetir na hora so dobra o tempo gasto num lote grande sem
 * ganhar nada.
 */
class EnrichChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        private readonly int $channelId,
        private readonly bool $force = false
    ) {
    }

    public function handle(ChannelEnricher $enricher): void
    {
        $channel = Channel::find($this->channelId);

        if (! $channel) {
            return;
        }

        $enricher->enrich($channel, $this->force);
    }
}
