<?php

namespace App\Domain\LoanProducts\Actions;

use App\Domain\Audit\RecordAuditLog;
use App\Enums\InterestModel;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateLoanProductAction
{
    public function __construct(
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     interest_model: string,
     *     interest_rate: numeric,
     *     term_unit: string,
     *     term_length: int,
     *     fee_amount?: numeric,
     *     is_active?: bool
     * }  $data
     */
    public function handle(array $data, ?User $actor = null, ?string $ip = null): LoanProduct
    {
        return DB::transaction(function () use ($data, $actor, $ip): LoanProduct {
            $product = LoanProduct::query()->create([
                'name' => $data['name'],
                'interest_model' => InterestModel::from($data['interest_model']),
                'interest_rate' => $data['interest_rate'],
                'term_unit' => $data['term_unit'],
                'term_length' => $data['term_length'],
                'fee_amount' => $data['fee_amount'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->recordAuditLog->handle(
                auditable: $product,
                action: 'loan_product.created',
                actor: $actor,
                after: [
                    'name' => $product->name,
                    'interest_model' => $product->interest_model->value,
                    'interest_rate' => (string) $product->interest_rate,
                    'term_unit' => $product->term_unit->value,
                    'term_length' => $product->term_length,
                    'fee_amount' => (string) $product->fee_amount,
                    'is_active' => $product->is_active,
                ],
                ip: $ip,
            );

            return $product;
        });
    }
}
