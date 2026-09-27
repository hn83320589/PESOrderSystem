<?php

namespace Tests\Feature\Customer;

use App\Http\Controllers\Customer\LineAuthController;
use App\Models\Customer;
use App\Models\FavoriteOrder;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FavoriteOrderTest extends TestCase
{
    use RefreshDatabase;

    private Customer $me;

    private ProductVariant $pipe;

    private ProductVariant $valve;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = Customer::factory()->withLine()->create();
        $this->pipe = ProductVariant::factory()->withStock(50)->create(['price' => 60]);
        $this->valve = ProductVariant::factory()->withStock(0)->create(['price' => 180]);
        $this->actAs($this->me);
    }

    private function actAs(Customer $customer): void
    {
        $this->actingAs($customer, 'customer')->withSession([LineAuthController::SESSION_LINE_SUB => $customer->line_user_id]);
    }

    private function save(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/customer/favorites', array_merge([
            'items' => [['variant_id' => $this->pipe->id, 'quantity' => 20], ['variant_id' => $this->valve->id, 'quantity' => 2]],
        ], $overrides));
    }

    public function test_save_with_default_name_and_list_with_current_info(): void
    {
        $this->save()->assertCreated()->assertJsonPath('data.name', '常用 1');
        $this->save(['name' => '工地標準包'])->assertCreated();
        $this->pipe->update(['price' => 65]);

        $this->getJson('/api/customer/favorites')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', '工地標準包')
            ->assertJsonPath('data.0.items.0.product_name', $this->pipe->product->name)
            ->assertJsonPath('data.0.items.0.quantity', 20)
            ->assertJsonPath('data.0.items.0.current.price', 65)
            ->assertJsonPath('data.0.items.0.current.in_stock', true)
            ->assertJsonPath('data.0.items.1.current.in_stock', false)
            ->assertJsonMissingPath('data.0.items.0.current.available');
    }

    public function test_discontinued_items_are_flagged(): void
    {
        $this->save();
        $this->valve->update(['is_active' => false]);

        $this->getJson('/api/customer/favorites')->assertJsonPath('data.0.items.1.current.orderable', false);
    }

    public function test_delete_own_favorite(): void
    {
        $id = $this->save()->json('data.id');

        $this->deleteJson("/api/customer/favorites/{$id}")->assertNoContent();
        $this->assertSame(0, FavoriteOrder::count());
    }

    public function test_cannot_see_or_delete_other_customers_favorites(): void
    {
        $id = $this->save()->json('data.id');
        $other = Customer::factory()->withLine()->create();
        $this->actAs($other);

        $this->getJson('/api/customer/favorites')->assertJsonCount(0, 'data');
        $this->deleteJson("/api/customer/favorites/{$id}")->assertNotFound();
        $this->assertSame(1, FavoriteOrder::count());
    }

    public function test_limit_per_customer(): void
    {
        foreach (range(1, FavoriteOrder::MAX_PER_CUSTOMER) as $i) {
            $this->save()->assertCreated();
        }

        $this->save()->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_validation(): void
    {
        $this->postJson('/api/customer/favorites', ['items' => [['variant_id' => 999, 'quantity' => 0]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.variant_id', 'items.0.quantity']);
    }

    public function test_requires_customer_login(): void
    {
        auth('customer')->logout();

        $this->getJson('/api/customer/favorites')->assertUnauthorized();
    }
}
