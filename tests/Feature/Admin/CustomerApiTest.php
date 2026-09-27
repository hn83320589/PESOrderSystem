<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(), 'web');
    }

    public function test_create_and_update_customer(): void
    {
        $id = $this->postJson('/api/admin/customers', [
            'name' => '大明水電行',
            'contact_name' => '王老闆',
            'phone' => '0912345678',
            'address' => '台中市北區',
            'billing_type' => 'monthly',
        ])->assertCreated()->assertJsonPath('data.billing_type_label', '月結')->json('data.id');

        $this->patchJson("/api/admin/customers/{$id}", ['phone' => '0922000000', 'billing_type' => 'cash_on_delivery'])
            ->assertOk()
            ->assertJsonPath('data.phone', '0922000000')
            ->assertJsonPath('data.billing_type', 'cash_on_delivery');
    }

    public function test_create_validates_billing_type(): void
    {
        $this->postJson('/api/admin/customers', ['name' => 'X', 'billing_type' => 'credit_card'])
            ->assertUnprocessable()->assertJsonValidationErrors('billing_type');
    }

    public function test_index_searches_name_contact_and_phone(): void
    {
        Customer::factory()->create(['name' => '大明水電行', 'phone' => '0911111111']);
        Customer::factory()->create(['name' => '小華電器', 'phone' => '0922222222']);

        $this->getJson('/api/admin/customers?'.http_build_query(['q' => '大明']))->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/customers?q=0922')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '小華電器');
    }

    public function test_bind_link_is_one_time_token_with_expiry(): void
    {
        $customer = Customer::factory()->create();

        $first = $this->postJson("/api/admin/customers/{$customer->id}/line-bind-link")
            ->assertOk()->json('data');
        $this->assertStringContainsString('/line/bind/', $first['url']);
        $this->assertNotNull($first['expires_at']);

        $second = $this->postJson("/api/admin/customers/{$customer->id}/line-bind-link")->json('data');
        $this->assertNotSame($first['url'], $second['url'], '重新產生後舊連結失效');

        $customer->refresh();
        $this->assertTrue(str_ends_with($second['url'], $customer->line_bind_token));
        $this->assertTrue($customer->line_bind_token_expires_at->isFuture());
    }

    public function test_bind_token_is_never_exposed_in_listing(): void
    {
        $customer = Customer::factory()->create();
        $this->postJson("/api/admin/customers/{$customer->id}/line-bind-link");

        $this->assertStringNotContainsString(
            $customer->fresh()->line_bind_token,
            $this->getJson('/api/admin/customers')->getContent(),
        );
    }

    public function test_unbind_line_account(): void
    {
        $customer = Customer::factory()->withLine()->create();

        $this->deleteJson("/api/admin/customers/{$customer->id}/line-binding")
            ->assertOk()->assertJsonPath('data.line_bound', false);
        $this->assertNull($customer->fresh()->line_user_id);
    }
}
