<?php

namespace App\Domain\Loans\Actions;

use App\Domain\Audit\RecordAuditLog;
use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateLoanApplicationAction
{
    public function __construct(
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    /**
     * @param  array{customer_id: int, loan_product_id: int, principal_amount: numeric, currency?: string}  $data
     */
    public function handle(array $data, ?User $actor = null, ?string $ip = null): Loan
    {
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $product = LoanProduct::query()->findOrFail($data['loan_product_id']);

        if (! $product->is_active) {
            throw ValidationException::withMessages([
                'loan_product_id' => ['The selected loan product is inactive.'],
            ]);
        }

        return DB::transaction(function () use ($data, $customer, $product, $actor, $ip): Loan {
            $loan = Loan::query()->create([
                'customer_id' => $customer->id,
                'loan_product_id' => $product->id,
                'principal_amount' => $data['principal_amount'],
                'currency' => $data['currency'] ?? 'KES',
                'status' => LoanStatus::Pending,
            ]);

            $this->recordAuditLog->handle(
                auditable: $loan,
                action: 'loan.created',
                actor: $actor,
                after: [
                    'customer_id' => $loan->customer_id,
                    'loan_product_id' => $loan->loan_product_id,
                    'principal_amount' => (string) $loan->principal_amount,
                    'status' => $loan->status->value,
                ],
                ip: $ip,
            );

            return $loan->load(['customer', 'loanProduct']);
        });
    }
}
