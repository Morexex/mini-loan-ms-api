<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\InterestModel;
use App\Enums\TermUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoanProductRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'interest_model' => ['sometimes', 'required', 'string', Rule::enum(InterestModel::class)],
            'interest_rate' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'term_unit' => ['sometimes', 'required', 'string', Rule::enum(TermUnit::class)],
            'term_length' => ['sometimes', 'required', 'integer', 'min:1', 'max:360'],
            'fee_amount' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'interest_model.Illuminate\Validation\Rules\Enum' => 'Only flat interest is supported in v1 (see ADR 0001).',
            'interest_model.enum' => 'Only flat interest is supported in v1 (see ADR 0001).',
        ];
    }
}
