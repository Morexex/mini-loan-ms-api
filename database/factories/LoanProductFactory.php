<?php

namespace Database\Factories;

use App\Enums\InterestModel;
use App\Enums\TermUnit;
use App\Models\LoanProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanProduct>
 */
class LoanProductFactory extends Factory
{
    protected $model = LoanProduct::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true).' Loan',
            'interest_model' => InterestModel::Flat,
            'interest_rate' => fake()->randomFloat(4, 1, 20),
            'term_unit' => fake()->randomElement([TermUnit::Months, TermUnit::Weeks]),
            'term_length' => fake()->numberBetween(4, 24),
            'fee_amount' => fake()->randomFloat(2, 0, 500),
            'is_active' => true,
        ];
    }
}
