<?php

namespace App\Domain\Reconciliation;

interface ReconciliationService
{
    /**
     * Attempt to satisfy an outstanding Payment Intent with evidence.
     * Milestone 11: ingest + candidate linking only (no allocation).
     * Milestone 12: full payment + installment allocation.
     */
    public function reconcile(PaymentEvidence $evidence): ReconciliationResult;
}
