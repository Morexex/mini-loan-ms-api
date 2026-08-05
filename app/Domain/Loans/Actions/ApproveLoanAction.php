<?php

namespace App\Domain\Loans\Actions;

use App\Domain\Audit\RecordAuditLog;
use App\Domain\Installments\InstallmentScheduleService;
use App\Domain\Loans\Support\LoanStatusGuard;
use App\Enums\LoanStatus;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApproveLoanAction
{
    public function __construct(
        private readonly LoanStatusGuard $statusGuard,
        private readonly InstallmentScheduleService $scheduleService,
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    public function handle(Loan $loan, ?User $actor = null, ?string $ip = null): Loan
    {
        $loan->loadMissing('loanProduct', 'installments');

        if ($loan->installments()->exists()) {
            throw new DomainException('Loan already has an installment schedule.');
        }

        $from = $loan->status;
        $to = LoanStatus::Approved;
        $this->statusGuard->assertCanTransition($from, $to);

        return DB::transaction(function () use ($loan, $from, $to, $actor, $ip): Loan {
            $approvedAt = CarbonImmutable::now();

            $loan->status = $to;
            $loan->approved_at = $approvedAt;
            $loan->save();

            $rows = $this->scheduleService->generate($loan, $loan->loanProduct, $approvedAt);
            Installment::query()->insert($rows->all());

            $this->recordAuditLog->handle(
                auditable: $loan,
                action: 'loan.approved',
                actor: $actor,
                before: ['status' => $from->value],
                after: [
                    'status' => $to->value,
                    'approved_at' => $approvedAt->toIso8601String(),
                    'installments' => $rows->count(),
                ],
                ip: $ip,
            );

            return $loan->load(['customer', 'loanProduct', 'installments']);
        });
    }
}
