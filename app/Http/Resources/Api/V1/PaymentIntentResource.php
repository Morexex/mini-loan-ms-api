<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PaymentIntent
 */
class PaymentIntentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'customer_id' => $this->customer_id,
            'loan_id' => $this->loan_id,
            'amount' => $this->amount,
            'phone' => $this->phone,
            'status' => $this->status?->value,
            'attempt_number' => $this->attempt_number,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            // Safaricom identifiers — metadata only, never primary join keys.
            'merchant_request_id' => $this->merchant_request_id,
            'checkout_request_id' => $this->checkout_request_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
