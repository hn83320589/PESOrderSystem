<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SpaShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 測試不需要實際編譯前端資源
        $this->withoutVite();
    }

    public function test_admin_paths_serve_the_admin_spa(): void
    {
        $this->get('/admin')->assertOk()->assertSee('id="admin-app"', false);
        $this->get('/admin/orders/123')->assertOk()->assertSee('id="admin-app"', false);
    }

    public function test_other_paths_serve_the_customer_spa(): void
    {
        $this->get('/')->assertOk()->assertSee('id="customer-app"', false);
        $this->get('/orders')->assertOk()->assertSee('id="customer-app"', false);
    }

    public function test_unknown_api_paths_return_json_404_instead_of_spa(): void
    {
        $this->getJson('/api/does-not-exist')->assertNotFound();
        $this->get('/api/does-not-exist')->assertNotFound()->assertDontSee('customer-app');
    }

    public function test_customer_guard_is_separate_from_staff_guard(): void
    {
        $customer = Customer::factory()->create();

        Auth::guard('customer')->login($customer);

        $this->assertTrue(Auth::guard('customer')->check());
        $this->assertFalse(Auth::guard('web')->check());
    }
}
