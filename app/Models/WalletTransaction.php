<?php

namespace App\Models;

use App\Enums\WalletTransactionReason;
use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected $fillable = [
        'wallet_account_id',
        'type',
        'amount',
        'balance_after',
        'reason',
        'payment_id',
        'loan_id',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'type' => WalletTransactionType::class,
            'reason' => WalletTransactionReason::class,
        ];
    }

    public function walletAccount(): BelongsTo
    {
        return $this->belongsTo(WalletAccount::class);
    }
}
