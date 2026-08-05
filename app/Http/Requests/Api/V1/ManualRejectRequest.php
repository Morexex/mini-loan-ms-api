<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ManualRejectRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
