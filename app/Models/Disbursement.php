<?php

namespace App\Models;

use App\Enums\DisbursementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Disbursement extends Model
{
    protected $fillable = [
        'uuid',
        'loan_id',
        'amount',
        'phone',
        'status',
        'requested_at',
        'completed_at',
        'daraja_conversation_id',
        'daraja_originator_conversation_id',
        'daraja_transaction_id',
        'request_payload',
        'response_payload',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => DisbursementStatus::class,
            'amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Disbursement $disbursement): void {
            $disbursement->uuid ??= (string) Str::uuid();
        });
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
