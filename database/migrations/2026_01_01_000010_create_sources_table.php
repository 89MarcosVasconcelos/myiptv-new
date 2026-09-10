<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            // de onde a lista/item veio
            $table->enum('type', ['url', 'upload', 'csv'])->default('url');
            $table->string('reference'); // url original, nome do arquivo csv, etc.
            $table->boolean('is_catalog')->default(false); // fonte curada pre-carregada
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
