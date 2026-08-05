<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Disbursement
 */
class DisbursementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'loan_id' => $this->loan_id,
            'amount' => $this->amount,
            'phone' => $this->phone,
            'status' => $this->status?->value,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'daraja_conversation_id' => $this->daraja_conversation_id,
            'daraja_originator_conversation_id' => $this->daraja_originator_conversation_id,
            'daraja_transaction_id' => $this->daraja_transaction_id,
            'error_message' => $this->error_message,
        ];
    }
}
