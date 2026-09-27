<?php

namespace Tests\Feature\Admin;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(), 'web');
    }

    private function createReservation(int $quantity = 5): int
    {
        $variant = ProductVariant::factory()->withStock(20)->create();

        return $this->postJson('/api/admin/reservations', [
            'customer_id' => Customer::factory()->create()->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'expires_at' => now()->addDays(14)->toDateTimeString(),
        ])->assertCreated()->json('data.id');
    }

    public function test_create_list_cancel_flow(): void
    {
        $id = $this->createReservation();

        $this->getJson('/api/admin/reservations?status=active')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.status_label', '預留中');

        $this->postJson("/api/admin/reservations/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/admin/reservations/{$id}/cancel")->assertUnprocessable();
    }

    public function test_create_rejects_quantity_over_available(): void
    {
        $variant = ProductVariant::factory()->withStock(3)->create();

        $this->postJson('/api/admin/reservations', [
            'customer_id' => Customer::factory()->create()->id,
            'product_variant_id' => $variant->id,
            'quantity' => 5,
            'expires_at' => now()->addDays(14)->toDateTimeString(),
        ])->assertUnprocessable()->assertJsonPath('available', 3);

        $this->assertSame(0, Reservation::count());
    }

    public function test_renew_creates_next_period(): void
    {
        $id = $this->createReservation();

        $this->postJson("/api/admin/reservations/{$id}/renew", ['expires_at' => now()->addDays(45)->toDateTimeString()])
            ->assertCreated()
            ->assertJsonPath('data.status', ReservationStatus::Active->value);
        $this->assertSame(2, Reservation::count());
    }
}
