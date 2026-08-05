<?php

namespace App\Domain\LoanProducts\Actions;

use App\Domain\Audit\RecordAuditLog;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateLoanProductAction
{
    public function __construct(
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(LoanProduct $product, array $data, ?User $actor = null, ?string $ip = null): LoanProduct
    {
        return DB::transaction(function () use ($product, $data, $actor, $ip): LoanProduct {
            $snapshot = static fn (LoanProduct $item): array => [
                'name' => $item->name,
                'interest_model' => $item->interest_model->value,
                'interest_rate' => (string) $item->interest_rate,
                'term_unit' => $item->term_unit->value,
                'term_length' => $item->term_length,
                'fee_amount' => (string) $item->fee_amount,
                'is_active' => $item->is_active,
            ];

            $before = $snapshot($product);

            $product->fill($data);
            $product->save();

            $this->recordAuditLog->handle(
                auditable: $product,
                action: 'loan_product.updated',
                actor: $actor,
                before: $before,
                after: $snapshot($product->refresh()),
                ip: $ip,
            );

            return $product;
        });
    }
}
