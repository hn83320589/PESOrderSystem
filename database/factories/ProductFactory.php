<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['PVC 水管', '電線', '開關', '插座', '水龍頭', '止水閥', '電線管', '接頭'])
                .' '.fake()->unique()->numerify('###'),
            'category' => fake()->randomElement(['水', '電']),
            'unit' => fake()->randomElement(['支', '捲', '個', '組']),
            'is_active' => true,
        ];
    }
}
