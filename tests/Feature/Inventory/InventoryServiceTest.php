<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = app(InventoryService::class);
    }

    private function stockOf(ProductVariant $variant): array
    {
        $row = $variant->inventory()->first();

        return [$row->on_hand, $row->reserved, $row->allocated];
    }

    public function test_adjust_changes_on_hand_and_logs_movement(): void
    {
        $variant = ProductVariant::factory()->create();
        $user = User::factory()->create();

        $this->inventory->adjust($variant->id, 50, $user, '進貨');
        $this->inventory->adjust($variant->id, -8, $user, '盤點短少');

        $this->assertSame([42, 0, 0], $this->stockOf($variant));
        $movement = InventoryMovement::latest('id')->first();
        $this->assertSame(InventoryMovementType::Adjust, $movement->type);
        $this->assertSame(-8, $movement->on_hand_change);
        $this->assertSame(42, $movement->on_hand_after);
        $this->assertSame($user->id, $movement->user_id);
        $this->assertSame('盤點短少', $movement->note);
    }

    public function test_adjust_cannot_reduce_below_committed_stock(): void
    {
        $variant = ProductVariant::factory()->withStock(10)->create();
        $this->inventory->allocate($variant->id, 7, Order::factory()->create());

        $this->expectException(InsufficientStockException::class);
        $this->inventory->adjust($variant->id, -4);
    }

    public function test_allocate_ship_and_release_follow_the_stock_flow(): void
    {
        $variant = ProductVariant::factory()->withStock(100)->create();
        $order = Order::factory()->create();

        $this->inventory->allocate($variant->id, 30, $order);
        $this->assertSame([100, 0, 30], $this->stockOf($variant));

        $this->inventory->ship($variant->id, 20, $order);
        $this->assertSame([80, 0, 10], $this->stockOf($variant));

        $this->inventory->releaseAllocation($variant->id, 10, $order);
        $this->assertSame([80, 0, 0], $this->stockOf($variant));

        $refs = InventoryMovement::where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->id)->pluck('type')->all();
        $this->assertSame([
            InventoryMovementType::Allocate,
            InventoryMovementType::Ship,
            InventoryMovementType::ReleaseAllocation,
        ], $refs);
    }

    public function test_allocate_more_than_available_is_rejected_without_side_effects(): void
    {
        $variant = ProductVariant::factory()->withStock(10)->create();

        try {
            $this->inventory->allocate($variant->id, 11, Order::factory()->create());
            $this->fail('應該要丟出庫存不足例外');
        } catch (InsufficientStockException $e) {
            $this->assertSame(10, $e->available);
            $this->assertSame(11, $e->requested);
        }

        $this->assertSame([10, 0, 0], $this->stockOf($variant));
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovementType::Allocate)->count());
    }

    public function test_reserved_stock_is_not_available_to_others(): void
    {
        $variant = ProductVariant::factory()->withStock(10)->create();
        $order = Order::factory()->create();

        $this->inventory->reserve($variant->id, 8, $order);

        $this->expectException(InsufficientStockException::class);
        $this->inventory->allocate($variant->id, 3, $order);
    }

    public function test_allocate_from_reservation_moves_reserved_to_allocated(): void
    {
        $variant = ProductVariant::factory()->withStock(10)->create();
        $order = Order::factory()->create();
        $this->inventory->reserve($variant->id, 8, $order);

        // 8 個來自預留 + 2 個來自一般可用量
        $this->inventory->allocate($variant->id, 10, $order, fromReserved: 8);

        $this->assertSame([10, 0, 10], $this->stockOf($variant));
    }

    public function test_release_reservation_returns_stock_to_available(): void
    {
        $variant = ProductVariant::factory()->withStock(10)->create();
        $order = Order::factory()->create();
        $this->inventory->reserve($variant->id, 6, $order);

        $this->inventory->releaseReservation($variant->id, 6, $order);

        $this->assertSame([10, 0, 0], $this->stockOf($variant));
        $this->assertSame(10, $variant->inventory()->first()->available());
    }

    public function test_quantities_must_be_positive(): void
    {
        $variant = ProductVariant::factory()->withStock(10)->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->inventory->allocate($variant->id, 0, Order::factory()->create());
    }
}
