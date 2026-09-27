<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'spec' => fake()->randomElement(['1/2"', '3/4"', '1"', '2.0mm²', '3.5mm²', '5.5mm²']),
            'sku' => fake()->unique()->bothify('SKU-####-??'),
            'price' => fake()->numberBetween(10, 2000),
            'is_active' => true,
        ];
    }

    public function withStock(int $onHand): static
    {
        return $this->afterCreating(fn (ProductVariant $variant) => $variant->inventory->update(['on_hand' => $onHand]));
    }
}
