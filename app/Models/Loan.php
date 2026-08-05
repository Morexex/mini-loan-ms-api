<?php

namespace App\Models;

use App\Enums\LoanStatus;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'loan_product_id',
        'principal_amount',
        'currency',
        'status',
        'approved_at',
        'disbursed_at',
        'activated_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LoanStatus::class,
            'principal_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('sequence');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class)->latest('id');
    }

    public function paymentIntents(): HasMany
    {
        return $this->hasMany(PaymentIntent::class)->latest('id');
    }
}
