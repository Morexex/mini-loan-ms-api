<?php

namespace App\Models;

use App\Enums\WebhookProcessingStatus;
use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    protected $fillable = [
        'provider',
        'idempotency_key',
        'headers',
        'payload',
        'processing_status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'payload' => 'array',
            'processing_status' => WebhookProcessingStatus::class,
        ];
    }
}
