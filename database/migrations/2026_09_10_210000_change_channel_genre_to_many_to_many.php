<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Genero deixa de ser 1-pra-1 (channels.genre_id) e passa a aceitar varios por
 * canal, via tabela pivot channel_genre. Os generos ja classificados sao
 * migrados pra pivot antes da coluna antiga ser removida — nada se perde.
 *
 * Idempotente de proposito (checks de hasTable/hasColumn): na primeira
 * tentativa o MySQL rejeitou o DROP INDEX porque ele ainda sustentava uma FK
 * (erro 1553) — a tabela channel_genre e a copia dos dados ja tinham sido
 * feitas antes disso. Tentar adivinhar o nome da FK pela convencao do
 * Laravel (dropForeign(['genre_id'])) nao resolveu — o nome real nao batia
 * com o esperado. Em vez de adivinhar de novo, esta versao pergunta ao
 * proprio MySQL (information_schema) quais FKs e indices existem em cima de
 * channels.genre_id e derruba cada um pelo nome real.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('channel_genre')) {
            Schema::create('channel_genre', function (Blueprint $table) {
                $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
                $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
                $table->primary(['channel_id', 'genre_id']);
            });
        }

        if (! Schema::hasColumn('channels', 'genre_id')) {
            return; // coluna ja foi removida numa tentativa anterior — nada mais a fazer
        }

        DB::table('channels')->whereNotNull('genre_id')->orderBy('id')
            ->select('id', 'genre_id')
            ->chunk(1000, function ($rows) {
                $data = $rows->map(fn ($r) => ['channel_id' => $r->id, 'genre_id' => $r->genre_id])->all();
                if ($data) {
                    DB::table('channel_genre')->insertOrIgnore($data);
                }
            });

        // Descobre pelo information_schema (em vez de adivinhar pela
        // convencao de nomes do Laravel) toda FK que aponta a partir de
        // channels.genre_id, e derruba cada uma pelo nome real.
        $foreignKeys = DB::select("
            SELECT DISTINCT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'channels'
              AND COLUMN_NAME = 'genre_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        foreach ($foreignKeys as $fk) {
            DB::statement("ALTER TABLE `channels` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
        }

        // Continuava dando erro 1553 mesmo depois da FK de genre_id cair:
        // o indice composto (country_id, mode_id, content_type_id, genre_id)
        // tambem sustenta a FK de country_id (que e o prefixo mais a
        // esquerda dele) — dropar so o indice deixaria essa OUTRA FK sem
        // indice de apoio, e o MySQL recusa isso mesmo num passo
        // intermediario. A saida e um unico ALTER TABLE que dropa o indice
        // velho E cria o novo (sem genre_id) ao mesmo tempo: o MySQL so
        // valida o resultado final, nunca um estado no meio do caminho.
        $indexes = DB::select("
            SELECT DISTINCT INDEX_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'channels'
              AND COLUMN_NAME = 'genre_id'
              AND INDEX_NAME != 'PRIMARY'
        ");

        foreach ($indexes as $idx) {
            $indexName = $idx->INDEX_NAME;

            $columns = DB::select('
                SELECT COLUMN_NAME
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND INDEX_NAME = ?
                ORDER BY SEQ_IN_INDEX
            ', ['channels', $indexName]);

            $remainingColumns = array_values(array_filter(
                array_map(fn ($c) => $c->COLUMN_NAME, $columns),
                fn ($col) => $col !== 'genre_id'
            ));

            if ($remainingColumns) {
                $cols = implode('`, `', $remainingColumns);
                DB::statement("ALTER TABLE `channels` DROP INDEX `{$indexName}`, ADD INDEX (`{$cols}`)");
            } else {
                DB::statement("ALTER TABLE `channels` DROP INDEX `{$indexName}`");
            }
        }

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('genre_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('channels', 'genre_id')) {
            $existing = DB::select("SHOW INDEX FROM channels WHERE Key_name = 'channels_country_id_mode_id_content_type_id_index'");
            if (! empty($existing)) {
                Schema::table('channels', function (Blueprint $table) {
                    $table->dropIndex(['country_id', 'mode_id', 'content_type_id']);
                });
            }

            Schema::table('channels', function (Blueprint $table) {
                $table->foreignId('genre_id')->nullable()->after('content_type_id')->constrained()->nullOnDelete();
            });

            DB::table('channel_genre')->orderBy('channel_id')->chunk(1000, function ($rows) {
                foreach ($rows as $row) {
                    // Se um canal tiver mais de um genero, so o primeiro sobrevive
                    // na volta pra 1-pra-1 — perda esperada de um downgrade.
                    DB::table('channels')->where('id', $row->channel_id)->whereNull('genre_id')
                        ->update(['genre_id' => $row->genre_id]);
                }
            });

            Schema::table('channels', function (Blueprint $table) {
                $table->index(['country_id', 'mode_id', 'content_type_id', 'genre_id']);
            });
        }

        Schema::dropIfExists('channel_genre');
    }
};
