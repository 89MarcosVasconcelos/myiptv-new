<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('url')->nullable(); // null quando vier de upload direto
            // m3u/m3u8 = precisa parse; media = link unico (mp4/mkv/etc vira 1 canal); unknown = ainda nao verificado
            $table->enum('format', ['m3u', 'm3u8', 'media', 'unknown'])->default('unknown');
            $table->enum('status', ['pending', 'processing', 'ok', 'failed'])->default('pending');
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('ok_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->json('epg_urls')->nullable(); // x-tvg-url e afins, capturados mas usados so na Fase 2
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlists');
    }
};
