<?php

namespace App\Domain\Disbursements\Actions;

use App\Domain\Audit\RecordAuditLog;
use App\Domain\Loans\Support\LoanStatusGuard;
use App\Enums\DisbursementStatus;
use App\Enums\LoanStatus;
use App\Jobs\SendB2cDisbursementJob;
use App\Models\Disbursement;
use App\Models\Loan;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class DisburseLoanAction
{
    public function __construct(
        private readonly LoanStatusGuard $statusGuard,
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    public function handle(Loan $loan, ?User $actor = null, ?string $ip = null): Loan
    {
        $loan->loadMissing(['customer', 'disbursements']);

        $this->assertCanDisburse($loan);

        $disbursementId = DB::transaction(function () use ($loan, $actor, $ip): int {
            $from = $loan->status;

            if ($from === LoanStatus::Approved) {
                $this->statusGuard->assertCanTransition($from, LoanStatus::DisbursementRequested);
                $loan->status = LoanStatus::DisbursementRequested;
                $loan->save();
            }

            $disbursement = Disbursement::query()->create([
                'loan_id' => $loan->id,
                'amount' => $loan->principal_amount,
                'phone' => $loan->customer->phone,
                'status' => DisbursementStatus::Pending,
                'requested_at' => now(),
            ]);

            $this->recordAuditLog->handle(
                auditable: $loan,
                action: 'loan.disbursement_requested',
                actor: $actor,
                before: ['status' => $from->value],
                after: [
                    'status' => $loan->status->value,
                    'disbursement_uuid' => $disbursement->uuid,
                ],
                ip: $ip,
            );

            return $disbursement->id;
        });

        // Sync keeps sandbox demos and feature tests deterministic without a worker.
        SendB2cDisbursementJob::dispatchSync($disbursementId);

        return $loan->fresh(['customer', 'loanProduct', 'installments', 'disbursements']);
    }

    private function assertCanDisburse(Loan $loan): void
    {
        if ($loan->status === LoanStatus::Approved) {
            return;
        }

        if ($loan->status === LoanStatus::DisbursementRequested) {
            $latest = $loan->disbursements->sortByDesc('id')->first();
            if ($latest?->status === DisbursementStatus::Failed) {
                return;
            }
        }

        throw new DomainException(
            "Loan cannot be disbursed from status [{$loan->status->value}]."
        );
    }
}
