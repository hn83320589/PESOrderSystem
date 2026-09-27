<?php

namespace Tests\Feature\Line;

use App\Exceptions\LineApiException;
use App\Services\Line\LineMessagingClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LineMessagingClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.line.channel_access_token' => 'test-token', 'services.line.channel_secret' => 'test-secret']);
    }

    public function test_push_sends_text_with_auth_and_retry_key(): void
    {
        Http::fake(['api.line.me/*' => Http::response(['sentMessages' => []])]);

        app(LineMessagingClient::class)->pushText('Uabc', "第一行\n第二行", 'b3a1c2d4-0000-4000-8000-000000000001');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.line.me/v2/bot/message/push'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('X-Line-Retry-Key', 'b3a1c2d4-0000-4000-8000-000000000001')
            && $request['to'] === 'Uabc'
            && $request['messages'] === [['type' => 'text', 'text' => "第一行\n第二行"]]);
    }

    public function test_conflict_means_already_accepted_and_counts_as_success(): void
    {
        Http::fake(['api.line.me/*' => Http::response(['message' => 'The retry key is already accepted'], 409)]);

        app(LineMessagingClient::class)->pushText('Uabc', 'hi', 'b3a1c2d4-0000-4000-8000-000000000001');

        Http::assertSentCount(1);
    }

    public function test_client_errors_are_permanent_and_server_errors_are_retryable(): void
    {
        $client = app(LineMessagingClient::class);
        Http::fake(['api.line.me/*' => Http::sequence()
            ->push(['message' => 'Invalid reply token'], 400)
            ->push([], 500)]);

        try {
            $client->pushText('Uabc', 'hi', 'b3a1c2d4-0000-4000-8000-000000000001');
            $this->fail('400 應丟出例外');
        } catch (LineApiException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('Invalid reply token', $e->getMessage());
        }

        try {
            $client->pushText('Uabc', 'hi', 'b3a1c2d4-0000-4000-8000-000000000002');
            $this->fail('500 應丟出例外');
        } catch (LineApiException $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_rate_limit_is_retryable(): void
    {
        Http::fake(['api.line.me/*' => Http::response([], 429)]);

        try {
            app(LineMessagingClient::class)->pushText('Uabc', 'hi', 'b3a1c2d4-0000-4000-8000-000000000001');
            $this->fail();
        } catch (LineApiException $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_monthly_quota_exhausted_is_permanent_with_clear_reason(): void
    {
        Http::fake(['api.line.me/*' => Http::response(['message' => 'You have reached your monthly limit.'], 429)]);

        try {
            app(LineMessagingClient::class)->pushText('Uabc', 'hi', 'b3a1c2d4-0000-4000-8000-000000000001');
            $this->fail();
        } catch (LineApiException $e) {
            $this->assertFalse($e->retryable, '額度用完重試也沒用');
            $this->assertStringContainsString('本月 LINE 訊息額度已用完', $e->getMessage());
        }
    }

    public function test_quota_returns_usage_and_limit(): void
    {
        Http::fake([
            'api.line.me/v2/bot/message/quota/consumption' => Http::response(['totalUsage' => 170]),
            'api.line.me/v2/bot/message/quota' => Http::response(['type' => 'limited', 'value' => 200]),
        ]);

        $this->assertSame(['used' => 170, 'limit' => 200], app(LineMessagingClient::class)->quota());
    }

    public function test_quota_without_limit(): void
    {
        Http::fake([
            'api.line.me/v2/bot/message/quota/consumption' => Http::response(['totalUsage' => 8000]),
            'api.line.me/v2/bot/message/quota' => Http::response(['type' => 'none']),
        ]);

        $this->assertSame(['used' => 8000, 'limit' => null], app(LineMessagingClient::class)->quota());
    }

    public function test_quota_is_null_when_unavailable(): void
    {
        Http::fake(['api.line.me/*' => Http::response([], 500)]);
        $this->assertNull(app(LineMessagingClient::class)->quota());

        config(['services.line.channel_access_token' => null]);
        $this->assertNull(app(LineMessagingClient::class)->quota());
    }

    public function test_is_configured_requires_both_credentials(): void
    {
        $this->assertTrue(app(LineMessagingClient::class)->isConfigured());

        config(['services.line.channel_access_token' => null]);
        $this->assertFalse(app(LineMessagingClient::class)->isConfigured());
    }

    public function test_signature_verification_uses_raw_body(): void
    {
        $body = '{"destination":"U1","events":[]}';
        $valid = base64_encode(hash_hmac('sha256', $body, 'test-secret', true));
        $client = app(LineMessagingClient::class);

        $this->assertTrue($client->verifySignature($body, $valid));
        $this->assertFalse($client->verifySignature($body.' ', $valid), '內容被改過就不成立');
        $this->assertFalse($client->verifySignature($body, null));
    }
}
