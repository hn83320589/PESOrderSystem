<?php

namespace Database\Factories;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_no' => fake()->unique()->numerify(now()->format('Ymd').'-####'),
            'customer_id' => Customer::factory(),
            'status' => OrderStatus::Pending,
            'payment_method' => PaymentMethod::BankTransfer,
            'total_amount' => 0,
            'source' => OrderSource::Admin,
        ];
    }
}
