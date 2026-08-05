<?php

namespace App\Domain\Wallet;

use App\Domain\Audit\RecordAuditLog;
use App\Enums\WalletTransactionReason;
use App\Enums\WalletTransactionType;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\WalletAccount;
use App\Models\WalletTransaction;
use InvalidArgumentException;

class CreditWalletAction
{
    public function __construct(
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    public function handle(
        WalletAccount $wallet,
        string $amount,
        WalletTransactionReason $reason,
        ?Payment $payment = null,
        ?Loan $loan = null,
        ?string $notes = null,
    ): WalletTransaction {
        if (bccomp($amount, '0', 2) !== 1) {
            throw new InvalidArgumentException('Wallet credit amount must be greater than zero.');
        }

        $wallet->refresh();
        $balanceAfter = bcadd((string) $wallet->balance, $amount, 2);

        $wallet->balance = $balanceAfter;
        $wallet->save();

        $txn = WalletTransaction::query()->create([
            'wallet_account_id' => $wallet->id,
            'type' => WalletTransactionType::Credit->value,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reason' => $reason->value,
            'payment_id' => $payment?->id,
            'loan_id' => $loan?->id,
            'created_by' => null,
            'notes' => $notes,
        ]);

        $this->recordAuditLog->handle(
            auditable: $wallet,
            action: 'wallet.credited',
            after: [
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'reason' => $reason->value,
                'payment_id' => $payment?->id,
            ],
            reason: $notes,
        );

        return $txn;
    }
}
