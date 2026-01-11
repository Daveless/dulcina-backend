<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;

// Rutas públicas
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Rutas protegidas
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Orders - Rutas específicas PRIMERO
    Route::get('/orders/stats', [OrderController::class, 'stats']);
    Route::apiResource('orders', OrderController::class);

    // Products - TODAS las rutas específicas ANTES de apiResource
    Route::prefix('products')->group(function () {
        Route::get('test-connections', [ProductController::class, 'testConnections']);
        Route::get('woo-sync-status', [ProductController::class, 'checkWooSyncStatus']);
        Route::get('meta/feeds', [ProductController::class, 'getMetaFeeds']);
        Route::get('woo/list', [ProductController::class, 'listWooProducts']);
        Route::post('sync-all', [ProductController::class, 'syncAll']);
    });

    // Products - apiResource AL FINAL
    Route::apiResource('products', ProductController::class);
});
