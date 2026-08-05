<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ManualMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'webhook_log_id' => ['required', 'integer', 'exists:webhook_logs,id'],
            'payment_intent_uuid' => ['required', 'uuid', 'exists:payment_intents,uuid'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
