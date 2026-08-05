<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Installment
 */
class InstallmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'due_date' => $this->due_date?->toDateString(),
            'principal_due' => $this->principal_due,
            'interest_due' => $this->interest_due,
            'fee_due' => $this->fee_due,
            'amount_due' => $this->amount_due,
            'amount_paid' => $this->amount_paid,
            'status' => $this->status?->value,
        ];
    }
}
