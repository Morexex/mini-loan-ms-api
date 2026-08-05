<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\LoanProduct
 */
class LoanProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'interest_model' => $this->interest_model?->value,
            'interest_rate' => $this->interest_rate,
            'term_unit' => $this->term_unit?->value,
            'term_length' => $this->term_length,
            'fee_amount' => $this->fee_amount,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
