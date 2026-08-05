<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Customers\Support\PhoneNormalizer;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCustomerRequest extends FormRequest
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
            'phone' => ['sometimes', 'required', 'string', 'max:32'],
            'id_number' => ['sometimes', 'required', 'string', 'max:64'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('phone')) {
                return;
            }

            /** @var Customer $customer */
            $customer = $this->route('customer');
            $normalizer = app(PhoneNormalizer::class);
            $normalized = $normalizer->tryNormalize((string) $this->input('phone'));

            if ($normalized === null) {
                $validator->errors()->add('phone', 'Phone number must be a valid Kenyan mobile MSISDN.');

                return;
            }

            $exists = Customer::query()
                ->where('phone', $normalized)
                ->where('id', '!=', $customer->id)
                ->exists();

            if ($exists) {
                $validator->errors()->add('phone', 'A customer with this phone number already exists.');
            }

            $this->merge(['phone' => $normalized]);
        });
    }
}
