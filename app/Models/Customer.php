<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'id_number',
        'email',
    ];

    public function walletAccount(): HasOne
    {
        return $this->hasOne(WalletAccount::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
