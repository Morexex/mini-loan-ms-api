<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stub for relation typing; full loan domain arrives in later milestones.
 */
class Loan extends Model
{
    protected $fillable = [
        'customer_id',
        'loan_product_id',
        'principal_amount',
        'currency',
        'status',
    ];

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
        return $this->hasMany(Installment::class);
    }
}
