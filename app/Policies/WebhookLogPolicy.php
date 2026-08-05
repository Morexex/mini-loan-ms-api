<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookLog;

class WebhookLogPolicy
{
    public function operate(User $user): bool
    {
        return true;
    }
}
