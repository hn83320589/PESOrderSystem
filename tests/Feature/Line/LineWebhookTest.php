<?php

namespace Tests\Feature\Line;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LineWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.line.channel_secret' => 'test-secret', 'services.line.channel_access_token' => 'test-token']);
    }

    private function sendWebhook(array $payload, ?string $signature = 'auto')
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json'];
        if ($signature === 'auto') {
            $headers['X-Line-Signature'] = base64_encode(hash_hmac('sha256', $body, 'test-secret', true));
        } elseif ($signature !== null) {
            $headers['X-Line-Signature'] = $signature;
        }

        // LINE 平台不會帶 Referer，覆蓋 TestCase 預設值以模擬真實請求
        return $this->withHeader('Referer', '')->call('POST', '/api/line/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    public function test_verification_request_with_valid_signature_returns_200(): void
    {
        $this->sendWebhook(['destination' => 'U0', 'events' => []])->assertOk();
    }

    public function test_invalid_or_missing_signature_is_rejected(): void
    {
        $this->sendWebhook(['destination' => 'U0', 'events' => []], 'forged')->assertStatus(400);
        $this->sendWebhook(['destination' => 'U0', 'events' => []], null)->assertStatus(400);
    }

    public function test_follow_and_unfollow_update_friend_status_of_bound_customer(): void
    {
        $customer = Customer::factory()->withLine()->create();
        $event = fn (string $type) => ['destination' => 'U0', 'events' => [[
            'type' => $type, 'source' => ['type' => 'user', 'userId' => $customer->line_user_id], 'timestamp' => 1,
        ]]];

        $this->sendWebhook($event('unfollow'))->assertOk();
        $this->assertFalse($customer->fresh()->line_is_friend);

        $this->sendWebhook($event('follow'))->assertOk();
        $this->assertTrue($customer->fresh()->line_is_friend);
    }

    public function test_events_from_unknown_users_are_ignored(): void
    {
        $this->sendWebhook(['destination' => 'U0', 'events' => [[
            'type' => 'follow', 'source' => ['type' => 'user', 'userId' => 'Uunknown'], 'timestamp' => 1,
        ]]])->assertOk();
    }
}
