<?php

namespace Tests\Feature\Admin;

use App\Enums\NotificationStatus;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\CustomerNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(), 'web');
    }

    public function test_list_line_notifications_with_status_filter(): void
    {
        config(['services.line.channel_access_token' => null]);
        $customer = Customer::factory()->withLine()->create(['name' => '大明水電']);
        app(CustomerNotifier::class)->notify($customer, 'test', '標題', '內容');

        $this->getJson('/api/admin/notifications?channel=line&status=skipped')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.name', '大明水電')
            ->assertJsonPath('data.0.error', '系統尚未設定 LINE 金鑰');
    }

    public function test_resend_skipped_notification_after_credentials_are_set(): void
    {
        config(['services.line.channel_access_token' => null]);
        $customer = Customer::factory()->withLine()->create();
        app(CustomerNotifier::class)->notify($customer, 'test', '標題', '內容');
        $log = NotificationLog::where('channel', 'line')->sole();

        config(['services.line.channel_access_token' => 'test-token', 'services.line.channel_secret' => 'test-secret']);
        Http::fake(['api.line.me/*' => Http::response([])]);

        $this->postJson("/api/admin/notifications/{$log->id}/resend")->assertOk();

        $this->assertSame(NotificationStatus::Sent, $log->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_cannot_resend_sent_or_site_notifications(): void
    {
        Http::fake(['api.line.me/*' => Http::response([])]);
        config(['services.line.channel_access_token' => 'test-token', 'services.line.channel_secret' => 'test-secret']);
        $customer = Customer::factory()->withLine()->create();
        app(CustomerNotifier::class)->notify($customer, 'test', '標題', '內容');

        foreach (NotificationLog::all() as $log) {
            $this->postJson("/api/admin/notifications/{$log->id}/resend")->assertUnprocessable();
        }
    }

    public function test_customer_listing_shows_friend_status(): void
    {
        $customer = Customer::factory()->withLine()->create();
        $customer->forceFill(['line_is_friend' => false])->save();

        $this->getJson('/api/admin/customers')->assertJsonPath('data.0.line_is_friend', false);
    }
}
