<?php

namespace Tests\Feature\Purchasing;

use App\Http\Controllers\Customer\LineAuthController;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(), 'web');
    }

    public function test_supplier_crud(): void
    {
        $id = $this->postJson('/api/admin/suppliers', ['name' => '大亞電線', 'contact_name' => '林業務', 'phone' => '04-2222-3333'])
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/admin/suppliers/{$id}", ['phone' => '04-9999-0000', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/admin/suppliers')->assertJsonPath('data.0.phone', '04-9999-0000');
        $this->postJson('/api/admin/suppliers', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_create_receive_and_list_purchase_orders(): void
    {
        $supplier = Supplier::factory()->create();
        $variant = ProductVariant::factory()->withStock(5)->create();

        $id = $this->postJson('/api/admin/purchase-orders', [
            'supplier_id' => $supplier->id,
            'items' => [['variant_id' => $variant->id, 'quantity' => 20, 'unit_cost' => 12.5]],
            'note' => '週三到貨',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'ordered')
            ->assertJsonPath('data.status_label', '已下單未到貨')
            ->assertJsonPath('data.total_cost', 250)
            ->assertJsonPath('data.items.0.unit_cost', 12.5)
            ->json('data.id');

        $this->getJson('/api/admin/purchase-orders?status=ordered')->assertJsonCount(1, 'data')->assertJsonPath('data.0.supplier.name', $supplier->name);
        $this->postJson("/api/admin/purchase-orders/{$id}/receive")->assertOk()->assertJsonPath('data.status', 'received');
        $this->postJson("/api/admin/purchase-orders/{$id}/receive")->assertUnprocessable();
        $this->assertSame(25, $variant->inventory()->first()->on_hand);
    }

    public function test_receive_now_and_cancel(): void
    {
        $supplier = Supplier::factory()->create();
        $variant = ProductVariant::factory()->create();

        $this->postJson('/api/admin/purchase-orders', [
            'supplier_id' => $supplier->id, 'receive_now' => true,
            'items' => [['variant_id' => $variant->id, 'quantity' => 3, 'unit_cost' => 100]],
        ])->assertCreated()->assertJsonPath('data.status', 'received');

        $id = $this->postJson('/api/admin/purchase-orders', [
            'supplier_id' => $supplier->id, 'items' => [['variant_id' => $variant->id, 'quantity' => 3, 'unit_cost' => 100]],
        ])->json('data.id');
        $this->postJson("/api/admin/purchase-orders/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_purchase_validation(): void
    {
        $this->postJson('/api/admin/purchase-orders', ['supplier_id' => 999, 'items' => [['variant_id' => 1, 'quantity' => -1, 'unit_cost' => -5]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id', 'items.0.variant_id', 'items.0.quantity', 'items.0.unit_cost']);
    }

    public function test_reorder_suggestions_endpoint(): void
    {
        $variant = ProductVariant::factory()->withStock(2)->create();

        $this->getJson('/api/admin/reorder-suggestions')
            ->assertOk()
            ->assertJsonFragment(['variant_id' => $variant->id, 'suggested_quantity' => 8]);
    }

    public function test_customers_cannot_access_purchasing(): void
    {
        auth('web')->logout();
        $this->actingAs(Customer::factory()->create(), 'customer');

        $this->getJson('/api/admin/purchase-orders')->assertUnauthorized();
        $this->getJson('/api/admin/suppliers')->assertUnauthorized();
        $this->getJson('/api/admin/reorder-suggestions')->assertUnauthorized();
    }

    public function test_cost_is_hidden_from_customer_catalog(): void
    {
        auth('web')->logout();
        $variant = ProductVariant::factory()->withStock(20)->create(['avg_cost' => 42]);
        $customer = Customer::factory()->withLine()->create();
        $this->actingAs($customer, 'customer')->withSession([LineAuthController::SESSION_LINE_SUB => $customer->line_user_id]);

        $this->assertStringNotContainsString('avg_cost', $this->getJson('/api/customer/products')->getContent());
    }
}
