<?php

use App\Http\Controllers\Api\V1\ChannelController;
use App\Http\Controllers\Api\V1\MetadataController;
use App\Http\Controllers\Api\V1\PlaylistController;
use Illuminate\Support\Facades\Route;

// Mesmo contrato v1 para web (sessao Sanctum) e app mobile (token Sanctum).
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/playlists', [PlaylistController::class, 'index']);
    Route::post('/playlists', [PlaylistController::class, 'store']);
    Route::post('/playlists/{playlist}/recheck', [PlaylistController::class, 'recheck']);

    Route::get('/channels', [ChannelController::class, 'index']);
    Route::patch('/channels/{channel}', [ChannelController::class, 'update']);
    Route::post('/channels/{channel}/recheck', [ChannelController::class, 'recheck']);

    Route::get('/metadata/{type}', [MetadataController::class, 'index']);
    Route::post('/metadata/{type}', [MetadataController::class, 'store']);
    Route::patch('/metadata/{type}/{id}', [MetadataController::class, 'update']);
    Route::delete('/metadata/{type}/{id}', [MetadataController::class, 'destroy']);
});
