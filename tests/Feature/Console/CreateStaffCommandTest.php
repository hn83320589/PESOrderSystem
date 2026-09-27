<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateStaffCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_staff_account_with_prompted_password(): void
    {
        $this->artisan('staff:create', ['email' => 'boss@shop.tw', 'name' => '老闆'])
            ->expectsQuestion('密碼（至少 10 碼，輸入時不會顯示）', 'correct-horse-9')
            ->expectsQuestion('再輸入一次密碼', 'correct-horse-9')
            ->expectsOutputToContain('已建立')
            ->assertSuccessful();

        $user = User::where('email', 'boss@shop.tw')->sole();
        $this->assertSame('老闆', $user->name);
        $this->assertTrue(Hash::check('correct-horse-9', $user->password));
    }

    public function test_rejects_short_or_mismatched_password(): void
    {
        $this->artisan('staff:create', ['email' => 'a@shop.tw', 'name' => 'A'])
            ->expectsQuestion('密碼（至少 10 碼，輸入時不會顯示）', 'short')
            ->assertFailed();

        $this->artisan('staff:create', ['email' => 'a@shop.tw', 'name' => 'A'])
            ->expectsQuestion('密碼（至少 10 碼，輸入時不會顯示）', 'long-enough-1')
            ->expectsQuestion('再輸入一次密碼', 'different-222')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_rejects_duplicate_or_invalid_email(): void
    {
        User::factory()->create(['email' => 'boss@shop.tw']);

        $this->artisan('staff:create', ['email' => 'boss@shop.tw', 'name' => '老闆'])->assertFailed();
        $this->artisan('staff:create', ['email' => 'not-an-email', 'name' => '老闆'])->assertFailed();
        $this->assertSame(1, User::count());
    }
}
