<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// Rutas públicas
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Rutas protegidas
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Pedidos
    Route::get('/orders/stats', [OrderController::class, 'stats']);
    Route::apiResource('orders', OrderController::class);

    // Productos
    Route::get('/products/meta/test-connection', [ProductController::class, 'testMetaConnection']);
    Route::post('/products/sync-meta', [ProductController::class, 'syncWithMeta']);
    Route::apiResource('products', ProductController::class);
});
