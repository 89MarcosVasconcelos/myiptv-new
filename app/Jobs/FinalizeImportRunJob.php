<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Models\Playlist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fecha o import_run e atualiza a playlist. A assimetria e intencional:
 * numa importacao nova, canal que falhou e descartado da grade (fica so como
 * historico em channel_checks); numa reavaliacao, o canal continua existindo
 * como failed/dead para o usuario decidir se exclui.
 */
class FinalizeImportRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $importRunId, private readonly int $playlistId)
    {
    }

    public function handle(): void
    {
        $importRun = ImportRun::find($this->importRunId);
        $playlist = Playlist::find($this->playlistId);

        $importRun?->update(['status' => 'completed']);
        $playlist?->refreshCounters();
        $playlist?->update(['status' => $playlist->failed_count > 0 && $playlist->ok_count === 0 ? 'failed' : 'ok']);
    }
}
