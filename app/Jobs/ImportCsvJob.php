<?php

namespace App\Jobs;

use App\Models\ContentType;
use App\Models\Country;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Mode;
use App\Models\Playlist;
use App\Models\Subtitle;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Processa um CSV grande (';' — url;nome;pais;modo;tipo;genero;idioma;legenda)
 * fora da requisicao HTTP. Ao contrario do textarea (poucos links, cada um
 * baixado e inspecionado por ImportPlaylistJob), o CSV ja traz URL e nome
 * conhecidos linha a linha — nao faz sentido baixar cada um so pra descobrir
 * o tipo, e inserir 20 mil linhas via Eloquent::create() uma a uma estourava
 * os 60s de execucao do PHP. Aqui o insert vai em lote (chunks de 1000) com
 * query builder puro, e so a validacao (probe HTTP + ffprobe) e enfileirada
 * por canal.
 */
class ImportCsvJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, SerializesModels;

    private const CHUNK = 1000;

    public int $timeout = 600;

    public function __construct(
        private readonly int $playlistId,
        private readonly string $storagePath
    ) {
    }

    public function handle(): void
    {
        $playlist = Playlist::findOrFail($this->playlistId);
        $playlist->update(['status' => 'processing', 'format' => 'media']);

        $lookup = $this->buildLookupMaps();

        $path = Storage::path($this->storagePath);
        $handle = fopen($path, 'r');
        $header = null;
        $now = now();
        $buffer = [];
        $channelIds = [];
        $seenHashes = [];
        $total = 0;

        while (($row = fgetcsv($handle, 4096, ';')) !== false) {
            if ($header === null) {
                $header = array_map(fn ($h) => strtolower(trim($h)), $row);
                continue;
            }

            $data = array_combine(array_slice($header, 0, count($row)), $row);
            $url = trim($data['url'] ?? '');

            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $hash = hash('sha256', $url);
            if (isset($seenHashes[$hash])) {
                continue; // duplicata dentro do proprio arquivo
            }
            $seenHashes[$hash] = true;

            $buffer[] = [
                'playlist_id' => $this->playlistId,
                'name' => trim($data['nome'] ?? $data['name'] ?? '') ?: 'Sem nome',
                'url' => $url,
                'url_hash' => $hash,
                'country_id' => $lookup['country'][$this->norm($data['pais'] ?? null)] ?? null,
                'mode_id' => $lookup['mode'][$this->norm($data['modo'] ?? null)] ?? null,
                'content_type_id' => $lookup['content_type'][$this->norm($data['tipo'] ?? null)] ?? null,
                'genre_id' => $lookup['genre'][$this->norm($data['genero'] ?? null)] ?? null,
                'language_id' => $lookup['language'][$this->norm($data['idioma'] ?? null)] ?? null,
                'subtitle_id' => $lookup['subtitle'][$this->norm($data['legenda'] ?? null)] ?? null,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $total++;

            if (count($buffer) >= self::CHUNK) {
                $channelIds = array_merge($channelIds, $this->flush($buffer));
                $buffer = [];
            }
        }

        if (! empty($buffer)) {
            $channelIds = array_merge($channelIds, $this->flush($buffer));
        }

        fclose($handle);
        Storage::delete($this->storagePath);

        $playlist->refreshCounters();

        if ($total === 0) {
            $playlist->update(['status' => 'failed', 'error_message' => 'Nenhuma URL valida encontrada no CSV.']);
            return;
        }

        // Importar so cria os canais (status "pending"). A validacao (probe
        // HTTP + ffprobe) e disparada manualmente pelo botao "Iniciar
        // verificacao" na tela de listas — ver PlaylistController::validate().
        $playlist->update(['status' => 'pending']);
    }

    /** Insere o lote e devolve os ids gerados, na mesma ordem. */
    private function flush(array $buffer): array
    {
        DB::table('channels')->insertOrIgnore($buffer);

        return DB::table('channels')
            ->where('playlist_id', $this->playlistId)
            ->whereIn('url_hash', array_column($buffer, 'url_hash'))
            ->pluck('id')
            ->all();
    }

    private function norm(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_strtolower($value);
    }

    /** Mapa "valor normalizado (code/slug/nome em minusculo)" => id, uma consulta por tabela. */
    private function buildLookupMaps(): array
    {
        $build = function (string $model, string $codeColumn) {
            return $model::query()->get([$codeColumn, 'name', 'id'])
                ->flatMap(fn ($row) => [
                    mb_strtolower($row->{$codeColumn}) => $row->id,
                    mb_strtolower($row->name) => $row->id,
                ])
                ->all();
        };

        return [
            'country' => $build(Country::class, 'code'),
            'mode' => $build(Mode::class, 'slug'),
            'content_type' => $build(ContentType::class, 'slug'),
            'genre' => $build(Genre::class, 'slug'),
            'language' => $build(Language::class, 'code'),
            'subtitle' => $build(Subtitle::class, 'code'),
        ];
    }
}
