<?php

namespace Tests\Feature\Purchasing;

use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Services\OrderService;
use App\Services\PurchaseService;
use App\Services\ReorderSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReorderSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function ship(ProductVariant $variant, int $quantity, int $daysAgo): void
    {
        $orders = app(OrderService::class);
        $this->travel(-$daysAgo)->days();
        $order = $orders->create(Customer::factory()->create(), [['variant_id' => $variant->id, 'quantity' => $quantity]], PaymentMethod::CashOnDelivery, OrderSource::Admin);
        $orders->confirm($order);
        $orders->ship($order->fresh());
        $this->travelBack();
    }

    private function suggestionFor(ProductVariant $variant): ?array
    {
        return collect(app(ReorderSuggestionService::class)->suggestions())->firstWhere('variant_id', $variant->id);
    }

    public function test_low_stock_is_suggested_up_to_threshold(): void
    {
        $variant = ProductVariant::factory()->withStock(3)->create();

        $this->assertSame(7, $this->suggestionFor($variant)['suggested_quantity']);
    }

    public function test_fast_movers_target_last_30_days_shipments(): void
    {
        $variant = ProductVariant::factory()->withStock(200)->create();
        $this->ship($variant, 120, daysAgo: 10);
        $this->ship($variant, 50, daysAgo: 45); // 超過 30 天不計

        $suggestion = $this->suggestionFor($variant);

        // 兩次出貨都扣實際庫存：200 − 120 − 50 = 30；但只有 30 天內的 120 計入目標量
        $this->assertSame(120, $suggestion['shipped_30_days']);
        $this->assertSame(30, $suggestion['available']);
        $this->assertSame(90, $suggestion['suggested_quantity']);
    }

    public function test_well_stocked_items_are_not_suggested(): void
    {
        $variant = ProductVariant::factory()->withStock(50)->create();

        $this->assertNull($this->suggestionFor($variant));
    }

    public function test_quantity_already_on_order_is_deducted(): void
    {
        $variant = ProductVariant::factory()->withStock(2)->create();
        app(PurchaseService::class)->create(Supplier::factory()->create(), [['variant_id' => $variant->id, 'quantity' => 5, 'unit_cost' => 10]]);

        $suggestion = $this->suggestionFor($variant);

        $this->assertSame(5, $suggestion['on_order']);
        $this->assertSame(3, $suggestion['suggested_quantity']);
    }

    public function test_suggestion_includes_last_supplier_and_cost(): void
    {
        $supplier = Supplier::factory()->create(['name' => '大亞電線']);
        $variant = ProductVariant::factory()->withStock(0)->create();
        app(PurchaseService::class)->create($supplier, [['variant_id' => $variant->id, 'quantity' => 4, 'unit_cost' => 88]], receiveNow: true);

        $suggestion = $this->suggestionFor($variant);

        $this->assertSame($supplier->id, $suggestion['last_supplier_id']);
        $this->assertSame('大亞電線', $suggestion['last_supplier_name']);
        $this->assertSame(88.0, $suggestion['last_unit_cost']);
    }

    public function test_inactive_variants_are_excluded(): void
    {
        $variant = ProductVariant::factory()->withStock(0)->create(['is_active' => false]);

        $this->assertNull($this->suggestionFor($variant));
    }
}
