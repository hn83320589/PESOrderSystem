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
