<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(), 'web');
        $this->customer = Customer::factory()->create();
        $this->variant = ProductVariant::factory()->withStock(100)->create(['price' => 50]);
    }

    private function createOrder(int $quantity = 3): array
    {
        return $this->postJson('/api/admin/orders', [
            'customer_id' => $this->customer->id,
            'payment_method' => 'bank_transfer',
            'items' => [['variant_id' => $this->variant->id, 'quantity' => $quantity]],
            'note' => '下午送',
        ])->assertCreated()->json('data');
    }

    public function test_create_order_on_behalf_of_customer(): void
    {
        $order = $this->createOrder(3);

        $this->assertSame('pending', $order['status']);
        $this->assertSame('待確認', $order['status_label']);
        $this->assertSame('匯款', $order['payment_method_label']);
        $this->assertSame(150, $order['total_amount']);
        $this->assertSame('admin', $order['source']);
        $this->assertSame('未收', $order['payment']['status_label']);
        $this->assertSame(3, $order['items'][0]['quantity']);
    }

    public function test_create_validates_input(): void
    {
        $this->postJson('/api/admin/orders', ['customer_id' => 999, 'payment_method' => 'bitcoin', 'items' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'payment_method', 'items']);
    }

    public function test_create_with_insufficient_stock_returns_422(): void
    {
        $this->postJson('/api/admin/orders', [
            'customer_id' => $this->customer->id,
            'payment_method' => 'cash_on_delivery',
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 101]],
        ])->assertUnprocessable()->assertJsonPath('available', 100);

        $this->assertSame(0, Order::count());
    }

    public function test_status_flow_through_api(): void
    {
        $id = $this->createOrder()['id'];

        $this->postJson("/api/admin/orders/{$id}/ship")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/admin/orders/{$id}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->postJson("/api/admin/orders/{$id}/ship")->assertOk()->assertJsonPath('data.status', 'shipped');
        $this->postJson("/api/admin/orders/{$id}/expire")->assertUnprocessable();
    }

    public function test_update_order_items(): void
    {
        $id = $this->createOrder(3)['id'];

        $this->patchJson("/api/admin/orders/{$id}", [
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 5]],
            'payment_method' => 'check',
        ])->assertOk()
            ->assertJsonPath('data.total_amount', 250)
            ->assertJsonPath('data.payment_method', 'check');
    }

    public function test_index_filters(): void
    {
        $first = $this->createOrder()['id'];
        $this->createOrder();
        $this->postJson("/api/admin/orders/{$first}/confirm");
        $other = Customer::factory()->create(['name' => '別家水電']);
        app(OrderService::class)->create($other, [['variant_id' => $this->variant->id, 'quantity' => 1]], PaymentMethod::Check, OrderSource::Admin);

        $this->getJson('/api/admin/orders')->assertJsonCount(3, 'data');
        $this->getJson('/api/admin/orders?status=confirmed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);
        $this->getJson("/api/admin/orders?customer_id={$other->id}")->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.name', '別家水電');
        $this->getJson('/api/admin/orders?date_from='.now()->addDay()->toDateString())->assertJsonCount(0, 'data');
    }

    public function test_show_includes_items_and_payment(): void
    {
        $id = $this->createOrder()['id'];

        $this->getJson("/api/admin/orders/{$id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product_name', $this->variant->product->name)
            ->assertJsonPath('data.note', '下午送')
            ->assertJsonStructure(['data' => ['payment' => ['status', 'amount']]]);
    }

    public function test_recent_orders_show_current_price_and_availability(): void
    {
        $this->createOrder(3);
        $this->variant->update(['price' => 70]);

        $this->getJson("/api/admin/customers/{$this->customer->id}/recent-orders")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.items.0.unit_price', 50)
            ->assertJsonPath('data.0.items.0.current.price', 70)
            ->assertJsonPath('data.0.items.0.current.available', 97)
            ->assertJsonPath('data.0.items.0.current.orderable', true);
    }
}
