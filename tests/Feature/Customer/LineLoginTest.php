<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LineLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.line_login.channel_id' => '1234567890', 'services.line_login.channel_secret' => 'login-secret']);
    }

    /** 模擬 LINE 平台：換 token 成功，驗證 id_token 回傳指定使用者與 session 中的 nonce */
    private function fakeLine(string $sub = 'Uline0001', string $name = '王老闆'): void
    {
        Http::fake([
            'api.line.me/oauth2/v2.1/token' => Http::response(['access_token' => 'at', 'id_token' => 'idt', 'token_type' => 'Bearer']),
            'api.line.me/oauth2/v2.1/verify' => fn (Request $r) => Http::response([
                'iss' => 'https://access.line.me', 'sub' => $sub, 'aud' => '1234567890', 'nonce' => $r['nonce'], 'name' => $name,
            ]),
        ]);
    }

    /** 走一次「導向 LINE → 回呼」流程 */
    private function loginViaLine(): TestResponse
    {
        $redirect = $this->get('/auth/line')->assertRedirect();
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        return $this->get('/auth/line/callback?'.http_build_query(['code' => 'code123', 'state' => $query['state']]));
    }

    public function test_redirect_to_line_with_state_nonce_and_friend_prompt(): void
    {
        $location = $this->get('/auth/line')->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://access.line.me/oauth2/v2.1/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('1234567890', $query['client_id']);
        $this->assertSame('openid profile', $query['scope']);
        $this->assertSame('aggressive', $query['bot_prompt']);
        $this->assertSame(url('/auth/line/callback'), $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
        $this->assertNotEmpty($query['nonce']);
    }

    public function test_bound_customer_logs_in(): void
    {
        $customer = Customer::factory()->create();
        $customer->forceFill(['line_user_id' => 'Uline0001'])->save();
        $this->fakeLine('Uline0001');

        $this->loginViaLine()->assertRedirect('/');

        $this->assertAuthenticatedAs($customer, 'customer');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/token')
            && $r['client_secret'] === 'login-secret' && $r['code'] === 'code123');
    }

    public function test_unbound_line_account_cannot_log_in(): void
    {
        $this->fakeLine('Ustranger');

        $this->loginViaLine()->assertRedirect('/login?error=not_bound');

        $this->assertGuest('customer');
    }

    public function test_inactive_customer_cannot_log_in(): void
    {
        $customer = Customer::factory()->create(['is_active' => false]);
        $customer->forceFill(['line_user_id' => 'Uline0001'])->save();
        $this->fakeLine('Uline0001');

        $this->loginViaLine()->assertRedirect('/login?error=not_bound');
        $this->assertGuest('customer');
    }

    public function test_state_mismatch_is_rejected(): void
    {
        $this->fakeLine();
        $this->get('/auth/line');

        $this->get('/auth/line/callback?code=x&state=forged')->assertRedirect('/login?error=state');
        $this->assertGuest('customer');
        Http::assertNothingSent();
    }

    public function test_user_cancelling_on_line_returns_to_login(): void
    {
        $this->get('/auth/line');

        $this->get('/auth/line/callback?error=access_denied&state=x')->assertRedirect('/login?error=cancelled');
    }

    public function test_bind_link_binds_line_account_and_logs_in(): void
    {
        $customer = Customer::factory()->create();
        $url = $customer->issueLineBindToken();
        $this->fakeLine('Unewline', '陳老闆');

        $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect('/auth/line');
        $this->loginViaLine()->assertRedirect('/');

        $customer->refresh();
        $this->assertSame('Unewline', $customer->line_user_id);
        $this->assertSame('陳老闆', $customer->line_display_name);
        $this->assertNotNull($customer->line_bound_at);
        $this->assertNull($customer->line_bind_token, '綁定連結只能用一次');
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_expired_or_unknown_bind_link_is_rejected(): void
    {
        $customer = Customer::factory()->create();
        $url = $customer->issueLineBindToken();
        $this->travel(Customer::LINE_BIND_TOKEN_DAYS + 1)->days();

        $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect('/login?error=bind_invalid');
        $this->get('/line/bind/not-a-real-token')->assertRedirect('/login?error=bind_invalid');
    }

    public function test_line_account_already_bound_to_another_customer_cannot_bind_again(): void
    {
        $existing = Customer::factory()->create();
        $existing->forceFill(['line_user_id' => 'Utaken'])->save();
        $customer = Customer::factory()->create();
        $url = $customer->issueLineBindToken();
        $this->fakeLine('Utaken');

        $this->get(parse_url($url, PHP_URL_PATH));
        $this->loginViaLine()->assertRedirect('/login?error=line_in_use');

        $this->assertNull($customer->fresh()->line_user_id);
    }

    public function test_unbinding_in_admin_ends_existing_customer_session(): void
    {
        $customer = Customer::factory()->create();
        $customer->forceFill(['line_user_id' => 'Uline0001'])->save();
        $this->fakeLine('Uline0001');
        $this->loginViaLine();
        $this->getJson('/api/customer/me')->assertOk();

        $customer->unbindLine();
        // 測試中多個請求共用同一個 app，guard 會快取上一個請求的客戶物件；
        // 真實環境每個請求都會重新讀取，這裡清掉快取以模擬新請求
        Auth::guard('customer')->forgetUser();

        $this->getJson('/api/customer/me')->assertUnauthorized();
        $this->assertGuest('customer');
    }

    public function test_logout(): void
    {
        $customer = Customer::factory()->create();
        $customer->forceFill(['line_user_id' => 'Uline0001'])->save();
        $this->fakeLine('Uline0001');
        $this->loginViaLine();

        $this->postJson('/api/customer/logout')->assertNoContent();
        $this->assertGuest('customer');
    }

    public function test_dev_login_route_is_not_available_outside_local(): void
    {
        $customer = Customer::factory()->create();

        $this->get("/dev/customer-login/{$customer->id}")->assertOk()->assertSee('customer-app', false);
        $this->assertGuest('customer');
        $this->assertFalse(Auth::guard('customer')->check());
    }
}
