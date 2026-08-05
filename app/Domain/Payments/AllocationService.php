<?php

namespace App\Domain\Payments;

use App\Domain\Audit\RecordAuditLog;
use App\Domain\Wallet\CreditWalletAction;
use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\WalletTransactionReason;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Collection;

/**
 * Applies a posted payment to a loan's installments (oldest due first).
 * Excess beyond remaining installment balances credits the customer wallet.
 */
class AllocationService
{
    public function __construct(
        private readonly CreditWalletAction $creditWallet,
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    /**
     * @return array{allocated: string, wallet_credit: string, allocations: Collection<int, PaymentAllocation>}
     */
    public function allocate(Payment $payment, Loan $loan): array
    {
        $remaining = bcadd((string) $payment->amount, '0', 2);
        $totalAllocated = '0.00';
        $created = collect();

        $installments = Installment::query()
            ->where('loan_id', $loan->id)
            ->whereIn('status', [
                InstallmentStatus::Scheduled->value,
                InstallmentStatus::Due->value,
                InstallmentStatus::PartiallyPaid->value,
                InstallmentStatus::Overdue->value,
            ])
            ->orderBy('due_date')
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();

        foreach ($installments as $installment) {
            if (bccomp($remaining, '0', 2) !== 1) {
                break;
            }

            $outstanding = bcsub((string) $installment->amount_due, (string) $installment->amount_paid, 2);
            if (bccomp($outstanding, '0', 2) !== 1) {
                continue;
            }

            $apply = bccomp($remaining, $outstanding, 2) === 1 ? $outstanding : $remaining;

            $allocation = PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'installment_id' => $installment->id,
                'amount' => $apply,
            ]);
            $created->push($allocation);

            $newPaid = bcadd((string) $installment->amount_paid, $apply, 2);
            $installment->amount_paid = $newPaid;
            $installment->status = $this->statusFor($installment, $newPaid);
            $installment->save();

            $remaining = bcsub($remaining, $apply, 2);
            $totalAllocated = bcadd($totalAllocated, $apply, 2);
        }

        $walletCredit = '0.00';
        if (bccomp($remaining, '0', 2) === 1) {
            $wallet = $loan->customer->walletAccount()->lockForUpdate()->firstOrFail();
            $this->creditWallet->handle(
                wallet: $wallet,
                amount: $remaining,
                reason: WalletTransactionReason::Overpayment,
                payment: $payment,
                loan: $loan,
                notes: 'Overpayment after installment allocation.',
            );
            $walletCredit = $remaining;
        }

        $this->maybeCompleteLoan($loan);

        $this->recordAuditLog->handle(
            auditable: $payment,
            action: 'payment.allocated',
            after: [
                'allocated' => $totalAllocated,
                'wallet_credit' => $walletCredit,
                'loan_id' => $loan->id,
                'allocation_count' => $created->count(),
            ],
        );

        return [
            'allocated' => $totalAllocated,
            'wallet_credit' => $walletCredit,
            'allocations' => $created,
        ];
    }

    private function statusFor(Installment $installment, string $amountPaid): InstallmentStatus
    {
        if (bccomp($amountPaid, (string) $installment->amount_due, 2) >= 0) {
            return InstallmentStatus::Paid;
        }

        if (bccomp($amountPaid, '0', 2) === 1) {
            return InstallmentStatus::PartiallyPaid;
        }

        if ($installment->due_date->isPast()) {
            return InstallmentStatus::Overdue;
        }

        return $installment->status === InstallmentStatus::Due
            ? InstallmentStatus::Due
            : InstallmentStatus::Scheduled;
    }

    private function maybeCompleteLoan(Loan $loan): void
    {
        $loan->refresh();

        $open = Installment::query()
            ->where('loan_id', $loan->id)
            ->where('status', '!=', InstallmentStatus::Paid->value)
            ->exists();

        if ($open) {
            return;
        }

        if ($loan->status === LoanStatus::Active) {
            $before = ['status' => $loan->status->value];
            $loan->status = LoanStatus::Completed;
            $loan->closed_at = now();
            $loan->save();

            $this->recordAuditLog->handle(
                auditable: $loan,
                action: 'loan.completed',
                before: $before,
                after: ['status' => $loan->status->value, 'closed_at' => $loan->closed_at?->toIso8601String()],
            );
        }
    }
}
