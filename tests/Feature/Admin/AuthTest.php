<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com', 'password' => 'secret123']);

        $this->postJson('/api/admin/login', ['email' => 'boss@example.com', 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('data.id', $user->id);
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.email', 'boss@example.com');

        $this->postJson('/api/admin/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_login_works_on_a_host_other_than_app_url(): void
    {
        // 正式網域與 APP_URL 不一致（或開發時換 port）時，同網域 SPA 仍須能登入
        User::factory()->create(['email' => 'boss@example.com', 'password' => 'secret123']);

        $this->withHeader('Referer', 'http://shop.example.test/admin/login')
            ->postJson('http://shop.example.test/api/admin/login', ['email' => 'boss@example.com', 'password' => 'secret123'])
            ->assertOk();
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'boss@example.com', 'password' => 'secret123']);

        $this->postJson('/api/admin/login', ['email' => 'boss@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest('web');
    }

    public function test_admin_api_requires_staff_login(): void
    {
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->getJson('/api/admin/products')->assertUnauthorized();
    }

    public function test_logged_in_customer_cannot_use_admin_api(): void
    {
        $this->actingAs(Customer::factory()->create(), 'customer');

        $this->getJson('/api/admin/products')->assertUnauthorized();
        $this->getJson('/api/admin/inventory')->assertUnauthorized();
        $this->getJson('/api/admin/reservations')->assertUnauthorized();
    }
}
