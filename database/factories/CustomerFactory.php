<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $suffix = fake()->unique()->numerify('########');

        return [
            'name' => fake()->name(),
            'phone' => '2547'.$suffix,
            'id_number' => fake()->unique()->numerify('########'),
            'email' => fake()->optional()->safeEmail(),
        ];
    }
}
