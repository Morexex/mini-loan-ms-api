<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LoanProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login']);
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::apiResource('customers', CustomerController::class)->except(['destroy']);
        Route::apiResource('loan-products', LoanProductController::class)->except(['destroy']);
    });
});
