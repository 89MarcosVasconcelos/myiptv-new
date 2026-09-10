<?php

use App\Http\Controllers\Api\V1\ChannelController;
use App\Http\Controllers\Api\V1\MetadataController;
use App\Http\Controllers\Api\V1\PlaylistController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Web\ChannelsController;
use App\Http\Controllers\Web\ListsController;
use App\Http\Controllers\Web\MetadataController as WebMetadataController;
use App\Http\Controllers\Web\PlayerController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/listas/importar', [ListsController::class, 'create'])->name('listas.importar');
    Route::get('/listas', [ListsController::class, 'index'])->name('listas.index');

    Route::get('/canais', [ChannelsController::class, 'index'])->name('canais.index');

    Route::get('/metadados/{type}', [WebMetadataController::class, 'index'])->name('metadados.index');

    Route::get('/player', [PlayerController::class, 'show'])->name('player.show');

    // API v1 chamada pelo próprio SPA (sessão + CSRF do middleware "web").
    // Guard por token (Sanctum, pro app mobile) fica pra Fase 3, quando o
    // install:api for feito com calma — por ora o front so autentica logado.
    Route::prefix('api/v1')->group(function () {
        Route::get('/playlists', [PlaylistController::class, 'index']);
        Route::post('/playlists', [PlaylistController::class, 'store']);
        Route::post('/playlists/{playlist}/validate', [PlaylistController::class, 'validate']);

        Route::get('/channels', [ChannelController::class, 'index']);
        Route::patch('/channels/{channel}', [ChannelController::class, 'update']);
        Route::post('/channels/{channel}/recheck', [ChannelController::class, 'recheck']);

        Route::get('/metadata/{type}', [MetadataController::class, 'index']);
        Route::post('/metadata/{type}', [MetadataController::class, 'store']);
        Route::patch('/metadata/{type}/{id}', [MetadataController::class, 'update']);
        Route::delete('/metadata/{type}/{id}', [MetadataController::class, 'destroy']);
    });
});

require __DIR__.'/auth.php';
