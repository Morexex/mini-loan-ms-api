<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LoanController;
use App\Http\Controllers\Api\V1\LoanProductController;
use App\Http\Controllers\Api\V1\PaymentIntentController;
use App\Http\Controllers\Api\V1\ReconciliationController;
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

        Route::get('loans', [LoanController::class, 'index']);
        Route::post('loans', [LoanController::class, 'store']);
        Route::get('loans/{loan}', [LoanController::class, 'show']);
        Route::get('loans/{loan}/installments', [LoanController::class, 'installments']);
        Route::post('loans/{loan}/approve', [LoanController::class, 'approve']);
        Route::post('loans/{loan}/disburse', [LoanController::class, 'disburse']);

        Route::get('loans/{loan}/payment-intents', [PaymentIntentController::class, 'index']);
        Route::post('loans/{loan}/payment-intents', [PaymentIntentController::class, 'store']);
        Route::get('payment-intents/{paymentIntent:uuid}', [PaymentIntentController::class, 'show']);

        Route::get('reconciliation/unmatched', [ReconciliationController::class, 'unmatched']);
        Route::get('reconciliation/candidate-intents', [ReconciliationController::class, 'candidateIntents']);
        Route::post('reconciliation/matches', [ReconciliationController::class, 'match']);
        Route::post('reconciliation/rejects', [ReconciliationController::class, 'reject']);
    });
});
