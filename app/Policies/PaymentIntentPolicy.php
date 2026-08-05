<?php

namespace App\Policies;

use App\Models\PaymentIntent;
use App\Models\User;

class PaymentIntentPolicy
{
    public function operate(User $user): bool
    {
        return true;
    }
}
