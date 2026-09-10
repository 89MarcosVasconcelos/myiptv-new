<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda o caminho do arquivo CSV original (storage/app/...) na fonte, pra
 * dar pra reimportar sem precisar reenviar o arquivo — antes esse caminho so
 * existia como argumento passado ao ImportCsvJob e se perdia pra sempre se o
 * job travasse ou caisse no meio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('import_path')->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('import_path');
        });
    }
};
