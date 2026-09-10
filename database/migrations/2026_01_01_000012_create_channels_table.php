<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('url');
            $table->string('url_hash', 64); // sha256(url), URL de IPTV facil passa de 500 chars
            $table->unsignedInteger('channel_number')->nullable(); // tvg-chno, quando existir
            $table->enum('stream_type', ['hls', 'mp4', 'mkv', 'youtube', 'unknown'])->default('unknown');
            $table->json('http_headers')->nullable(); // user-agent / referrer exigidos pelo stream

            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mode_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('content_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('genre_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('language_id')->nullable()->constrained('languages')->nullOnDelete();
            $table->foreignId('subtitle_id')->nullable()->constrained('subtitles')->nullOnDelete();

            $table->enum('status', ['pending', 'ok', 'failed', 'dead'])->default('pending');
            $table->unsignedTinyInteger('consecutive_failures')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['playlist_id', 'url_hash']);
            $table->index(['status']);
            $table->index(['country_id', 'mode_id', 'content_type_id', 'genre_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
