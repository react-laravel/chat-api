<?php

use App\Http\Controllers\Api\Auth\SsoController;
use App\Http\Controllers\Api\EmbedController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('/embed/config', [EmbedController::class, 'show'])->middleware('throttle:60,1');
    Route::post('/auth/exchange', [SsoController::class, 'exchange']);

    Route::middleware('chat.auth')->group(function (): void {
        Route::get('/user', [SsoController::class, 'user']);
        Route::post('/auth/logout', [SsoController::class, 'logout']);

        Route::post('/broadcasting/auth', function (Request $request) {
            return Broadcast::auth($request);
        });

        require base_path('routes/api/chat.php');
    });
});
