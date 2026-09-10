<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campo livre de descricao/sinopse do canal — preenchido manualmente ou pelo
 * job de enriquecimento (TMDb/OMDb/TVmaze/iptv-org). Nullable e sem tamanho
 * fixo (TEXT) porque sinopses de filme/serie podem ser longas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('channels', 'description')) {
            return;
        }

        Schema::table('channels', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
