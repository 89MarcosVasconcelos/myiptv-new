<?php

use App\Http\Controllers\Web\ChannelsController;
use App\Http\Controllers\Web\ListsController;
use App\Http\Controllers\Web\MetadataController;
use App\Http\Controllers\Web\PlayerController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('/listas/importar', [ListsController::class, 'create'])->name('listas.importar');
    Route::get('/listas', [ListsController::class, 'index'])->name('listas.index');

    Route::get('/canais', [ChannelsController::class, 'index'])->name('canais.index');

    Route::get('/metadados/{type}', [MetadataController::class, 'index'])->name('metadados.index');

    Route::get('/player', [PlayerController::class, 'show'])->name('player.show');
});

require __DIR__ . '/auth.php';
