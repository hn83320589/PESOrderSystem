<?php

namespace Tests\Feature\Admin;

use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'web');
    }

    public function test_adjust_records_who_and_why(): void
    {
        $variant = ProductVariant::factory()->withStock(10)->create();

        $this->postJson("/api/admin/inventory/{$variant->id}/adjust", ['delta' => 25, 'note' => '進貨'])
            ->assertOk()
            ->assertJsonPath('data.on_hand', 35)
            ->assertJsonPath('data.available', 35);

        $this->getJson("/api/admin/inventory/{$variant->id}/movements")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'adjust')
            ->assertJsonPath('data.0.type_label', '庫存調整')
            ->assertJsonPath('data.0.on_hand_change', 25)
            ->assertJsonPath('data.0.user_name', $this->user->name)
            ->assertJsonPath('data.0.note', '進貨');
    }

    public function test_adjust_below_committed_stock_returns_422(): void
    {
        $variant = ProductVariant::factory()->withStock(3)->create();

        $this->postJson("/api/admin/inventory/{$variant->id}/adjust", ['delta' => -5, 'note' => '盤點'])
            ->assertUnprocessable()
            ->assertJsonPath('available', 3);
    }

    public function test_adjust_requires_non_zero_delta_and_note(): void
    {
        $variant = ProductVariant::factory()->create();

        $this->postJson("/api/admin/inventory/{$variant->id}/adjust", ['delta' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['delta', 'note']);
    }

    public function test_index_can_filter_low_stock(): void
    {
        ProductVariant::factory()->withStock(3)->create(['spec' => 'LOW']);
        ProductVariant::factory()->withStock(100)->create(['spec' => 'HIGH']);

        $this->getJson('/api/admin/inventory?low_stock=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.spec', 'LOW');
    }
}
