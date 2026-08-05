<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Installment extends Model
{
    protected $fillable = [
        'loan_id',
        'sequence',
        'due_date',
        'principal_due',
        'interest_due',
        'fee_due',
        'amount_due',
        'amount_paid',
        'status',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
