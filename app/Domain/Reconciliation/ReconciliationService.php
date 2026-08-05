<?php

namespace App\Domain\Reconciliation;

use App\Models\PaymentIntent;
use App\Models\User;

interface ReconciliationService
{
    /**
     * Attempt to satisfy an outstanding Payment Intent with evidence.
     */
    public function reconcile(PaymentEvidence $evidence): ReconciliationResult;

    /**
     * Operator-forced match (manual recon). May target expired intents.
     */
    public function reconcileToIntent(
        PaymentEvidence $evidence,
        PaymentIntent $intent,
        ?User $actor = null,
        ?string $reason = null,
    ): ReconciliationResult;
}
