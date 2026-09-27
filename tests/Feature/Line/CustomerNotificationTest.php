<?php

namespace Tests\Feature\Line;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Exceptions\LineApiException;
use App\Jobs\SendLineNotification;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\ProductVariant;
use App\Services\CustomerNotifier;
use App\Services\Line\LineMessagingClient;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.line.channel_access_token' => 'test-token', 'services.line.channel_secret' => 'test-secret']);
    }

    private function lineLog(Customer $customer): NotificationLog
    {
        return NotificationLog::where('customer_id', $customer->id)->where('channel', NotificationChannel::Line)->sole();
    }

    public function test_bound_customer_gets_site_and_line_notification(): void
    {
        Http::fake(['api.line.me/*' => Http::response([])]);
        $customer = Customer::factory()->withLine()->create();

        app(CustomerNotifier::class)->notify($customer, 'test', '標題', '內容');

        $this->assertSame(1, NotificationLog::where('channel', NotificationChannel::Site)->count());
        $log = $this->lineLog($customer);
        $this->assertSame(NotificationStatus::Sent, $log->status);
        $this->assertNotNull($log->sent_at);
        Http::assertSent(fn (Request $r) => $r['to'] === $customer->line_user_id
            && str_contains($r['messages'][0]['text'], '標題')
            && $r->hasHeader('X-Line-Retry-Key', $log->retry_key));
    }

    public function test_unbound_customer_is_skipped_with_reason(): void
    {
        Http::fake();
        $customer = Customer::factory()->create();

        app(CustomerNotifier::class)->notify($customer, 'test', '標題', '內容');

        $log = $this->lineLog($customer);
        $this->assertSame(NotificationStatus::Skipped, $log->status);
        $this->assertStringContainsString('尚未綁定', $log->error);
        Http::assertNothingSent();
    }

    public function test_missing_credentials_skip_without_breaking_the_flow(): void
    {
        config(['services.line.channel_access_token' => null]);
        Http::fake();
        $customer = Customer::factory()->withLine()->create();

        app(CustomerNotifier::class)->notify($customer, 'test', '標題', '內容');

        $this->assertSame(NotificationStatus::Skipped, $this->lineLog($customer)->status);
        Http::assertNothingSent();
    }

    public function test_permanent_failure_is_recorded(): void
    {
        Http::fake(['api.line.me/*' => Http::response(['message' => 'Failed to send messages'], 400)]);
        $customer = Customer::factory()->withLine()->create();

        app(CustomerNotifier::class)->notify($customer, 'test', '標題', '內容');

        $log = $this->lineLog($customer);
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertStringContainsString('Failed to send messages', $log->error);
    }

    public function test_temporary_failure_is_retried_with_the_same_retry_key(): void
    {
        Queue::fake();
        $customer = Customer::factory()->withLine()->create();
        app(CustomerNotifier::class)->notify($customer, 'test', '標題', '內容');
        $log = $this->lineLog($customer);
        $job = new SendLineNotification($log->id);

        Http::fake(['api.line.me/*' => Http::sequence()->push([], 503)->push([])]);
        try {
            $job->handle(app(LineMessagingClient::class));
            $this->fail('503 應丟出例外讓 queue 重試');
        } catch (LineApiException) {
        }
        $this->assertSame(NotificationStatus::Pending, $log->fresh()->status);

        $job->handle(app(LineMessagingClient::class));
        $this->assertSame(NotificationStatus::Sent, $log->fresh()->status);
        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('X-Line-Retry-Key')[0])->unique();
        $this->assertSame([$log->retry_key], $keys->values()->all(), '重試必須沿用同一組 retry key');
    }

    public function test_monthly_quota_exhausted_fails_immediately_without_retry(): void
    {
        Http::fake(['api.line.me/*' => Http::response(['message' => 'You have reached your monthly limit.'], 429)]);
        $customer = Customer::factory()->withLine()->create();

        app(CustomerNotifier::class)->notify($customer, 'test', '標題', '內容');

        $log = $this->lineLog($customer);
        $this->assertSame(NotificationStatus::Failed, $log->status);
        $this->assertStringContainsString('本月 LINE 訊息額度已用完', $log->error);
        Http::assertSentCount(1);
    }

    public function test_job_marks_failed_after_exhausting_retries(): void
    {
        Queue::fake();
        $customer = Customer::factory()->withLine()->create();
        app(CustomerNotifier::class)->notify($customer, 'test', '標題', '內容');
        $log = $this->lineLog($customer);

        (new SendLineNotification($log->id))->failed(new LineApiException('LINE 推播失敗（HTTP 503）', true));

        $this->assertSame(NotificationStatus::Failed, $log->fresh()->status);
        $this->assertStringContainsString('重試多次仍失敗', $log->fresh()->error);
    }

    public function test_confirming_an_order_notifies_the_customer_with_bank_account(): void
    {
        config(['shop.bank.account' => '123-456-789012', 'shop.bank.name' => '台灣銀行 西屯分行']);
        Http::fake(['api.line.me/*' => Http::response([])]);
        $customer = Customer::factory()->withLine()->create();
        $variant = ProductVariant::factory()->withStock(10)->create(['price' => 100]);
        $orders = app(OrderService::class);
        $order = $orders->create($customer, [['variant_id' => $variant->id, 'quantity' => 3]], PaymentMethod::BankTransfer, OrderSource::Admin);

        Http::assertNothingSent();
        $orders->confirm($order);

        $log = $this->lineLog($customer);
        $this->assertSame('order_confirmed', $log->type);
        $this->assertSame($order->id, $log->related_id);
        foreach ([$order->order_no, '$300', '匯款', '123-456-789012'] as $expected) {
            $this->assertStringContainsString($expected, $log->body);
        }
    }
}
