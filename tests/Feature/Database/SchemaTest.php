<?php

namespace Tests\Feature\Database;

use App\Enums\BillingType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_builds_a_usable_dataset(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThanOrEqual(1, User::count());
        $this->assertGreaterThanOrEqual(20, Product::count());
        $this->assertSame(ProductVariant::count(), Inventory::count(), '每個規格都要有一筆庫存');
        $this->assertGreaterThanOrEqual(10, Customer::count());
        $this->assertGreaterThan(0, Order::count());
    }

    public function test_variant_belongs_to_product_and_has_one_inventory(): void
    {
        $variant = ProductVariant::factory()->create();

        $this->assertInstanceOf(Product::class, $variant->product);
        $this->assertInstanceOf(Inventory::class, $variant->inventory);
        $this->assertSame(0, $variant->inventory->available());
    }

    public function test_inventory_available_excludes_reserved_and_allocated(): void
    {
        $variant = ProductVariant::factory()->create();
        $variant->inventory->update(['on_hand' => 100, 'reserved' => 20, 'allocated' => 30]);

        $this->assertSame(50, $variant->inventory->fresh()->available());
    }

    public function test_order_items_keep_a_snapshot_of_product_data(): void
    {
        $variant = ProductVariant::factory()->create(['price' => 120]);
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->for($variant, 'variant')->create();

        $variant->update(['price' => 999, 'spec' => '改過的規格']);

        $item->refresh();
        $this->assertSame(120, $item->unit_price);
        $this->assertNotSame('改過的規格', $item->spec);
    }

    public function test_customer_relations_and_enum_casts(): void
    {
        $customer = Customer::factory()->create(['billing_type' => BillingType::Monthly]);
        $order = Order::factory()->for($customer)->create([
            'status' => OrderStatus::Pending,
            'payment_method' => PaymentMethod::BankTransfer,
        ]);
        $reservation = Reservation::factory()->for($customer)->create();

        $this->assertSame(BillingType::Monthly, $customer->fresh()->billing_type);
        $this->assertTrue($customer->orders->contains($order));
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(ReservationStatus::Active, $reservation->fresh()->status);
        $this->assertTrue($customer->reservations->contains($reservation));
    }
}
