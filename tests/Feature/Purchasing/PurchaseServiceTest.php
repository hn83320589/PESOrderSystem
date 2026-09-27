<?php

namespace Tests\Feature\Purchasing;

use App\Enums\InventoryMovementType;
use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseOrderStatus;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseService $purchases;

    private Supplier $supplier;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->purchases = app(PurchaseService::class);
        $this->supplier = Supplier::factory()->create(['name' => '南亞塑膠經銷']);
        $this->staff = User::factory()->create();
    }

    public function test_order_first_then_receive_adds_stock_only_on_receipt(): void
    {
        $variant = ProductVariant::factory()->withStock(10)->create();

        $po = $this->purchases->create($this->supplier, [['variant_id' => $variant->id, 'quantity' => 50, 'unit_cost' => 42.5]], $this->staff);

        $this->assertSame(PurchaseOrderStatus::Ordered, $po->status);
        $this->assertMatchesRegularExpression('/^P\d{8}-\d{4,}$/', $po->po_no);
        $this->assertSame(2125.0, $po->total_cost);
        $this->assertSame(10, $variant->inventory()->first()->on_hand, '下單時不加庫存');

        $this->purchases->receive($po, $this->staff);

        $this->assertSame(PurchaseOrderStatus::Received, $po->fresh()->status);
        $this->assertNotNull($po->fresh()->received_at);
        $this->assertSame(60, $variant->inventory()->first()->on_hand);
        $movement = InventoryMovement::where('type', InventoryMovementType::Purchase)->sole();
        $this->assertSame(50, $movement->on_hand_change);
        $this->assertSame($po->getMorphClass(), $movement->reference_type);
        $this->assertSame($this->staff->id, $movement->user_id);
    }

    public function test_receive_now_for_goods_picked_up_on_the_spot(): void
    {
        $variant = ProductVariant::factory()->withStock(0)->create();

        $po = $this->purchases->create($this->supplier, [['variant_id' => $variant->id, 'quantity' => 20, 'unit_cost' => 30]], $this->staff, receiveNow: true);

        $this->assertSame(PurchaseOrderStatus::Received, $po->status);
        $this->assertSame(20, $variant->inventory()->first()->on_hand);
    }

    public function test_moving_average_cost(): void
    {
        $variant = ProductVariant::factory()->withStock(0)->create();

        $this->purchases->create($this->supplier, [['variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 40]], receiveNow: true);
        $this->assertSame(40.0, $variant->fresh()->avg_cost);

        // (10×40 + 30×48) ÷ 40 = 46
        $this->purchases->create($this->supplier, [['variant_id' => $variant->id, 'quantity' => 30, 'unit_cost' => 48]], receiveNow: true);
        $this->assertSame(46.0, $variant->fresh()->avg_cost);
    }

    public function test_first_cost_applies_to_existing_uncosted_stock(): void
    {
        // 系統上線前的庫存沒有成本，第一次進價即視為其成本
        $variant = ProductVariant::factory()->withStock(100)->create();

        $this->purchases->create($this->supplier, [['variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 25]], receiveNow: true);

        $this->assertSame(25.0, $variant->fresh()->avg_cost);
    }

    public function test_cost_is_snapshotted_when_order_ships(): void
    {
        $variant = ProductVariant::factory()->withStock(0)->create(['price' => 100]);
        $this->purchases->create($this->supplier, [['variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 60]], receiveNow: true);
        $orders = app(OrderService::class);
        $order = $orders->create(Customer::factory()->create(), [['variant_id' => $variant->id, 'quantity' => 3]], PaymentMethod::CashOnDelivery, OrderSource::Admin);
        $orders->confirm($order);
        $orders->ship($order->fresh());

        // 之後進價變動不影響已出貨的成本
        $this->purchases->create($this->supplier, [['variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 90]], receiveNow: true);

        $this->assertSame(60.0, $order->items()->first()->unit_cost);
    }

    public function test_cannot_receive_twice_or_after_cancel(): void
    {
        $variant = ProductVariant::factory()->create();
        $received = $this->purchases->create($this->supplier, [['variant_id' => $variant->id, 'quantity' => 5, 'unit_cost' => 10]], receiveNow: true);
        $cancelled = $this->purchases->create($this->supplier, [['variant_id' => $variant->id, 'quantity' => 5, 'unit_cost' => 10]]);
        $this->purchases->cancel($cancelled, $this->staff);

        foreach ([$received, $cancelled] as $po) {
            try {
                $this->purchases->receive($po->fresh());
                $this->fail('不可重複入庫或對已取消的進貨單入庫');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(5, $variant->inventory()->first()->on_hand);
    }

    public function test_received_orders_cannot_be_cancelled(): void
    {
        $variant = ProductVariant::factory()->create();
        $po = $this->purchases->create($this->supplier, [['variant_id' => $variant->id, 'quantity' => 5, 'unit_cost' => 10]], receiveNow: true);

        $this->expectException(ValidationException::class);
        $this->purchases->cancel($po);
    }

    public function test_items_are_validated(): void
    {
        $variant = ProductVariant::factory()->create();

        $this->expectException(ValidationException::class);
        $this->purchases->create($this->supplier, [['variant_id' => $variant->id, 'quantity' => 0, 'unit_cost' => 10]]);
    }

    public function test_duplicate_lines_are_merged_with_weighted_cost(): void
    {
        $variant = ProductVariant::factory()->create();

        $po = $this->purchases->create($this->supplier, [
            ['variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 10],
            ['variant_id' => $variant->id, 'quantity' => 30, 'unit_cost' => 14],
        ]);

        $this->assertCount(1, $po->items);
        $this->assertSame(40, $po->items[0]->quantity);
        $this->assertSame(13.0, $po->items[0]->unit_cost);
        $this->assertSame(520.0, $po->total_cost);
        $this->assertSame(1, PurchaseOrder::count());
    }
}
