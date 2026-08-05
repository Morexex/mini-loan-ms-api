<?php

namespace App\Models;

use App\Enums\InterestModel;
use App\Enums\TermUnit;
use Database\Factories\LoanProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanProduct extends Model
{
    /** @use HasFactory<LoanProductFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'interest_model',
        'interest_rate',
        'term_unit',
        'term_length',
        'fee_amount',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'interest_model' => InterestModel::class,
            'term_unit' => TermUnit::class,
            'interest_rate' => 'decimal:4',
            'fee_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
