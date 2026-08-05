<?php

namespace App\Policies;

use App\Models\User;

/**
 * Baseline ops authorization for Milestone 4.
 * Domain-specific policies (Customer, Loan, etc.) will refine this later.
 */
class OpsAccessPolicy
{
    public function operate(User $user): bool
    {
        return true;
    }
}
