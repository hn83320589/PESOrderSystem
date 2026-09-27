<?php

namespace Database\Factories;

use App\Enums\BillingType;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->numerify('客戶水電行 ###'),
            'contact_name' => fake()->randomElement(['陳', '林', '黃', '張', '李', '王']).fake()->randomElement(['老闆', '師傅', '先生', '小姐']),
            'phone' => fake()->numerify('09########'),
            'address' => fake()->numerify('台中市西屯區工業路 ### 號'),
            'billing_type' => fake()->randomElement(BillingType::cases()),
            'is_active' => true,
        ];
    }

    public function withLine(): static
    {
        return $this->state(fn () => [
            'line_user_id' => 'U'.Str::lower(Str::random(32)),
            'line_display_name' => fake()->firstName(),
            'line_bound_at' => now(),
        ]);
    }
}
