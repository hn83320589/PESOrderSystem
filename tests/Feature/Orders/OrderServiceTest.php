<?php

namespace Tests\Feature\Orders;

use App\Enums\InventoryMovementType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $orders;

    private Customer $customer;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orders = app(OrderService::class);
        $this->customer = Customer::factory()->create();
        $this->staff = User::factory()->create();
    }

    private function variant(int $price = 100, int $stock = 50): ProductVariant
    {
        return ProductVariant::factory()->withStock($stock)->create(['price' => $price]);
    }

    private function place(array $items, PaymentMethod $method = PaymentMethod::BankTransfer): Order
    {
        return $this->orders->create($this->customer, $items, $method, OrderSource::Admin, $this->staff);
    }

    private function allocated(ProductVariant $variant): int
    {
        return $variant->inventory()->first()->allocated;
    }

    public function test_create_snapshots_items_totals_and_allocates_stock(): void
    {
        $pipe = $this->variant(price: 60);
        $valve = $this->variant(price: 180);

        $order = $this->place([
            ['variant_id' => $pipe->id, 'quantity' => 10],
            ['variant_id' => $valve->id, 'quantity' => 2],
        ]);

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertMatchesRegularExpression('/^\d{8}-\d{4,}$/', $order->order_no);
        $this->assertSame(960, $order->total_amount);
        $this->assertSame($this->staff->id, $order->created_by);
        $this->assertCount(2, $order->items);
        $this->assertSame($pipe->product->name, $order->items[0]->product_name);
        $this->assertSame(600, $order->items[0]->subtotal);
        $this->assertSame(10, $this->allocated($pipe));

        $this->assertSame(PaymentStatus::Unpaid, $order->payment->status);
        $this->assertSame(960, $order->payment->amount);
        $this->assertSame(PaymentMethod::BankTransfer, $order->payment->method);
    }

    public function test_duplicate_lines_are_merged(): void
    {
        $pipe = $this->variant();

        $order = $this->place([
            ['variant_id' => $pipe->id, 'quantity' => 3],
            ['variant_id' => $pipe->id, 'quantity' => 4],
        ]);

        $this->assertCount(1, $order->items);
        $this->assertSame(7, $order->items[0]->quantity);
    }

    public function test_insufficient_stock_rolls_back_the_whole_order(): void
    {
        $plenty = $this->variant(stock: 50);
        $scarce = $this->variant(stock: 1);

        try {
            $this->place([
                ['variant_id' => $plenty->id, 'quantity' => 5],
                ['variant_id' => $scarce->id, 'quantity' => 2],
            ]);
            $this->fail('應該庫存不足');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(0, Order::count());
        $this->assertSame(0, $this->allocated($plenty), '第一個品項的佔用也要一併復原');
    }

    public function test_inactive_products_cannot_be_ordered(): void
    {
        $variant = $this->variant();
        $variant->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $this->place([['variant_id' => $variant->id, 'quantity' => 1]]);
    }

    public function test_inactive_parent_product_cannot_be_ordered(): void
    {
        $variant = $this->variant();
        Product::whereKey($variant->product_id)->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $this->place([['variant_id' => $variant->id, 'quantity' => 1]]);
    }

    public function test_order_consumes_customer_reservation_first(): void
    {
        $variant = $this->variant(stock: 10);
        $reservation = app(ReservationService::class)->create($this->customer, $variant->id, 8, now()->addDays(10));

        // 可用量只剩 2，但客戶自己的預留 8 可以用
        $order = $this->place([['variant_id' => $variant->id, 'quantity' => 10]]);

        $inventory = $variant->inventory()->first();
        $this->assertSame([10, 0, 10], [$inventory->on_hand, $inventory->reserved, $inventory->allocated]);
        $reservation->refresh();
        $this->assertSame(ReservationStatus::Fulfilled, $reservation->status);
        $this->assertSame($order->id, $reservation->order_id);
    }

    public function test_ordering_less_than_reserved_releases_the_remainder(): void
    {
        $variant = $this->variant(stock: 20);
        app(ReservationService::class)->create($this->customer, $variant->id, 8, now()->addDays(10));

        $this->place([['variant_id' => $variant->id, 'quantity' => 5]]);

        $inventory = $variant->inventory()->first();
        $this->assertSame(0, $inventory->reserved);
        $this->assertSame(5, $inventory->allocated);
        $this->assertSame(15, $inventory->available());
    }

    public function test_other_customers_reservations_are_not_used(): void
    {
        $variant = $this->variant(stock: 10);
        app(ReservationService::class)->create(Customer::factory()->create(), $variant->id, 8, now()->addDays(10));

        $this->expectException(InsufficientStockException::class);
        $this->place([['variant_id' => $variant->id, 'quantity' => 3]]);
    }

    public function test_update_applies_only_the_difference_and_keeps_original_prices(): void
    {
        $pipe = $this->variant(price: 60);
        $valve = $this->variant(price: 180);
        $tape = $this->variant(price: 12);
        $order = $this->place([
            ['variant_id' => $pipe->id, 'quantity' => 10],
            ['variant_id' => $valve->id, 'quantity' => 2],
        ]);
        $pipe->update(['price' => 999]);

        $order = $this->orders->update($order, [
            ['variant_id' => $pipe->id, 'quantity' => 12],
            ['variant_id' => $tape->id, 'quantity' => 5],
        ], user: $this->staff);

        $this->assertSame(12, $this->allocated($pipe));
        $this->assertSame(0, $this->allocated($valve));
        $this->assertSame(5, $this->allocated($tape));

        $pipeItem = $order->items->firstWhere('product_variant_id', $pipe->id);
        $this->assertSame(60, $pipeItem->unit_price, '既有品項沿用下單時單價');
        $this->assertSame(12 * 60 + 5 * 12, $order->total_amount);
        $this->assertSame($order->total_amount, $order->payment->amount);

        $pipeMoves = InventoryMovement::where('product_variant_id', $pipe->id)
            ->where('type', InventoryMovementType::Allocate)->pluck('allocated_change')->all();
        $this->assertSame([10, 2], $pipeMoves, '改單只記錄差額');
    }

    public function test_update_can_change_payment_method_and_note(): void
    {
        $order = $this->place([['variant_id' => $this->variant()->id, 'quantity' => 1]]);

        $order = $this->orders->update($order, null, PaymentMethod::Check, '週五前送達', $this->staff);

        $this->assertSame(PaymentMethod::Check, $order->payment_method);
        $this->assertSame(PaymentMethod::Check, $order->payment->method);
        $this->assertSame('週五前送達', $order->note);
    }

    public function test_confirm_then_ship_deducts_on_hand(): void
    {
        $variant = $this->variant(stock: 50);
        $order = $this->place([['variant_id' => $variant->id, 'quantity' => 10]]);

        $this->orders->confirm($order, $this->staff);
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->confirmed_at);

        $this->orders->ship($order->fresh(), $this->staff);
        $inventory = $variant->inventory()->first();
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $this->assertSame([40, 0], [$inventory->on_hand, $inventory->allocated]);
    }

    public function test_shipping_requires_confirmation_first(): void
    {
        $order = $this->place([['variant_id' => $this->variant()->id, 'quantity' => 1]]);

        $this->expectException(ValidationException::class);
        $this->orders->ship($order, $this->staff);
    }

    public function test_expire_releases_allocation(): void
    {
        $variant = $this->variant(stock: 50);
        $order = $this->place([['variant_id' => $variant->id, 'quantity' => 10]]);

        $this->orders->expire($order, $this->staff);

        $this->assertSame(OrderStatus::Expired, $order->fresh()->status);
        $this->assertSame(0, $this->allocated($variant));
        $this->assertSame(50, $variant->inventory()->first()->available());
    }

    public function test_shipped_orders_cannot_be_modified_or_expired(): void
    {
        $variant = $this->variant();
        $order = $this->place([['variant_id' => $variant->id, 'quantity' => 1]]);
        $this->orders->confirm($order, $this->staff);
        $this->orders->ship($order->fresh(), $this->staff);

        foreach ([
            fn () => $this->orders->update($order->fresh(), [['variant_id' => $variant->id, 'quantity' => 2]]),
            fn () => $this->orders->expire($order->fresh()),
        ] as $action) {
            try {
                $action();
                $this->fail('已出貨訂單不可再修改或失效');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
    }

    public function test_paid_orders_cannot_be_modified(): void
    {
        $variant = $this->variant();
        $order = $this->place([['variant_id' => $variant->id, 'quantity' => 1]]);
        $order->payment->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        $this->expectException(ValidationException::class);
        $this->orders->update($order->fresh(), [['variant_id' => $variant->id, 'quantity' => 5]]);
    }

    public function test_recent_orders_for_reorder(): void
    {
        $variant = $this->variant(stock: 500);
        foreach (range(1, 12) as $i) {
            $this->travel(1)->hours();
            $this->place([['variant_id' => $variant->id, 'quantity' => $i]]);
        }
        $expired = $this->place([['variant_id' => $variant->id, 'quantity' => 99]]);
        $this->orders->expire($expired);
        $otherCustomerOrder = $this->orders->create(Customer::factory()->create(), [['variant_id' => $variant->id, 'quantity' => 1]], PaymentMethod::BankTransfer, OrderSource::Admin);

        $recent = $this->orders->recentForReorder($this->customer);

        $this->assertCount(10, $recent);
        $this->assertSame(12, $recent->first()->items->first()->quantity, '最新的排最前面');
        $this->assertFalse($recent->contains($expired), '已失效的訂單不列入');
        $this->assertFalse($recent->contains($otherCustomerOrder));
    }
}
