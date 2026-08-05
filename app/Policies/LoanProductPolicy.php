<?php

namespace App\Policies;

use App\Models\LoanProduct;
use App\Models\User;

class LoanProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LoanProduct $loanProduct): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, LoanProduct $loanProduct): bool
    {
        return true;
    }
}
