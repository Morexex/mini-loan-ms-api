<?php

namespace App\Models;

use App\Enums\PaymentIntentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentIntent extends Model
{
    protected $fillable = [
        'uuid',
        'customer_id',
        'loan_id',
        'amount',
        'phone',
        'status',
        'attempt_number',
        'expires_at',
        'submitted_at',
        'merchant_request_id',
        'checkout_request_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentIntentStatus::class,
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentIntent $intent): void {
            $intent->uuid ??= (string) Str::uuid();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            PaymentIntentStatus::Pending,
            PaymentIntentStatus::Submitted,
            PaymentIntentStatus::AwaitingCallback,
        ], true);
    }
}
