<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => fake()->numberBetween(1, 20),
        ];
    }

    public function configure(): static
    {
        // 未指定時，從規格帶入快照欄位
        return $this->afterMaking(function (OrderItem $item) {
            $variant = $item->variant()->with('product')->first();
            $item->product_name ??= $variant->product->name;
            $item->spec ??= $variant->spec;
            $item->unit ??= $variant->product->unit;
            $item->unit_price ??= $variant->price;
            $item->subtotal ??= $item->unit_price * $item->quantity;
        });
    }
}
