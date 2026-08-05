<?php

namespace App\Domain\Loans\Support;

use App\Enums\LoanStatus;
use DomainException;

class LoanStatusGuard
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'pending' => ['approved'],
        'approved' => ['disbursement_requested'],
        'disbursement_requested' => ['disbursed', 'approved'],
        'disbursed' => ['active'],
        'active' => ['completed', 'closed'],
        'completed' => ['closed'],
        'closed' => [],
    ];

    public function assertCanTransition(LoanStatus $from, LoanStatus $to): void
    {
        $allowed = self::ALLOWED[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw new DomainException(
                "Illegal loan status transition from [{$from->value}] to [{$to->value}]."
            );
        }
    }
}
