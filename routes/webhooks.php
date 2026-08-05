<?php

use App\Http\Controllers\Webhooks\DarajaB2cWebhookController;
use App\Http\Controllers\Webhooks\DarajaStkWebhookController;
use App\Http\Controllers\Webhooks\SmsForwarderWebhookController;
use App\Http\Middleware\VerifySmsForwarderSecret;
use Illuminate\Support\Facades\Route;

Route::prefix('webhooks')->middleware('throttle:webhooks')->group(function (): void {
    Route::post('daraja/stk', DarajaStkWebhookController::class);
    Route::post('daraja/b2c', DarajaB2cWebhookController::class);

    Route::post('sms-forwarder', SmsForwarderWebhookController::class)
        ->middleware(VerifySmsForwarderSecret::class);
});
