<?php

namespace Database\Factories;

use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    protected $model = Loan::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'loan_product_id' => LoanProduct::factory(),
            'principal_amount' => fake()->randomFloat(2, 1000, 50000),
            'currency' => 'KES',
            'status' => LoanStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => LoanStatus::Approved,
            'approved_at' => now(),
        ]);
    }
}
