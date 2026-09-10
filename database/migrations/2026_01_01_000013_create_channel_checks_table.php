<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['ok', 'failed'])->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('detected_content_type')->nullable();
            $table->boolean('ffprobe_ok')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_checks');
    }
};
