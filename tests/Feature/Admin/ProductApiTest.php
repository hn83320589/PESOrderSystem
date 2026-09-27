<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(), 'web');
    }

    public function test_index_lists_products_with_variants_and_stock(): void
    {
        ProductVariant::factory()->withStock(12)->create(['spec' => '3/4"']);

        $this->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonPath('data.0.variants.0.spec', '3/4"')
            ->assertJsonPath('data.0.variants.0.stock.on_hand', 12)
            ->assertJsonPath('data.0.variants.0.stock.available', 12);
    }

    public function test_store_creates_product_with_variants_and_empty_stock(): void
    {
        $response = $this->postJson('/api/admin/products', [
            'name' => 'PVC 水管',
            'category' => '水',
            'unit' => '支',
            'variants' => [
                ['spec' => '1/2"', 'price' => 60],
                ['spec' => '3/4"', 'price' => 75, 'sku' => 'PVC-34'],
            ],
        ])->assertCreated();

        $product = Product::findOrFail($response->json('data.id'));
        $this->assertSame(2, $product->variants()->count());
        $this->assertSame(0, $product->variants()->first()->inventory->on_hand);
    }

    public function test_store_validates_input(): void
    {
        $this->postJson('/api/admin/products', ['name' => '', 'variants' => [['spec' => '', 'price' => -1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'unit', 'variants.0.spec', 'variants.0.price']);
    }

    public function test_update_product_and_variant(): void
    {
        $variant = ProductVariant::factory()->create();

        $this->patchJson("/api/admin/products/{$variant->product_id}", ['name' => '新品名', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', '新品名');
        $this->patchJson("/api/admin/variants/{$variant->id}", ['price' => 88])
            ->assertOk()->assertJsonPath('data.price', 88);

        $this->assertFalse($variant->product->fresh()->is_active);
    }

    public function test_add_variant_to_existing_product(): void
    {
        $product = Product::factory()->create();

        $this->postJson("/api/admin/products/{$product->id}/variants", ['spec' => '1"', 'price' => 90])
            ->assertCreated()->assertJsonPath('data.stock.on_hand', 0);
    }

    public function test_stock_quantities_cannot_be_changed_through_product_endpoints(): void
    {
        $variant = ProductVariant::factory()->withStock(5)->create();

        $this->patchJson("/api/admin/variants/{$variant->id}", ['price' => 10, 'on_hand' => 999])->assertOk();

        $this->assertSame(5, $variant->inventory()->first()->on_hand);
    }
}
