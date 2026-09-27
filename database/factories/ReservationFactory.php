<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => fake()->numberBetween(5, 50),
            'expires_at' => now()->addDays(30),
            'status' => ReservationStatus::Active,
        ];
    }
}
