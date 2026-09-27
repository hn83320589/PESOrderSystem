<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->numerify('供應商 ###'),
            'contact_name' => fake()->randomElement(['陳經理', '林業務', '黃先生']),
            'phone' => fake()->numerify('04-2###-####'),
            'is_active' => true,
        ];
    }
}
