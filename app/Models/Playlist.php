<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Playlist extends Model
{
    protected $fillable = [
        'source_id', 'name', 'url', 'format', 'status',
        'total_count', 'ok_count', 'failed_count', 'pending_count',
        'epg_urls', 'error_message',
    ];

    protected $casts = [
        'epg_urls' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(ImportRun::class);
    }

    /**
     * Recalcula os contadores materializados a partir dos canais reais.
     * Chamado ao fim de cada lote de validacao, e agora tambem sempre que a
     * tela "Listas carregadas" carrega (GET /api/v1/playlists) — assim a
     * porcentagem mostrada nunca fica errada, mesmo se algum job rodou mais
     * de uma vez (retry, worker que travou e reiniciou no meio, etc.) e o
     * incremento manual dos contadores saiu da realidade.
     *
     * Importante: NAO deixamos isso tocar 'updated_at'. A tela usa esse
     * campo pra decidir se uma importacao esta "travada" (isStuckImport no
     * Lists/Index.vue); se cada recalculo de contador avancasse o timestamp,
     * uma lista de fato travada nunca envelheceria o suficiente pra mostrar
     * o botao de recuperacao.
     */
    public function refreshCounters(): void
    {
        $this->timestamps = false;

        $this->forceFill([
            'total_count' => $this->channels()->count(),
            'ok_count' => $this->channels()->where('status', 'ok')->count(),
            'failed_count' => $this->channels()->whereIn('status', ['failed', 'dead'])->count(),
            'pending_count' => $this->channels()->where('status', 'pending')->count(),
        ])->save();

        $this->timestamps = true;
    }
}
