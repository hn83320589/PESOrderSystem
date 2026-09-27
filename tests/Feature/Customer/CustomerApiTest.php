<?php

namespace Tests\Feature\Customer;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Customer\LineAuthController;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CustomerNotifier;
use App\Services\OrderService;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    private Customer $me;

    private Customer $other;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->me = Customer::factory()->withLine()->create(['name' => '我的水電行']);
        $this->other = Customer::factory()->withLine()->create(['name' => '別家水電行']);
        $this->variant = ProductVariant::factory()->withStock(100)->create(['price' => 50]);
        $this->actingAsCustomer($this->me);
    }

    private function actingAsCustomer(Customer $customer): void
    {
        $this->actingAs($customer, 'customer')->withSession([LineAuthController::SESSION_LINE_SUB => $customer->line_user_id]);
    }

    private function orderFor(Customer $customer, int $quantity = 2): Order
    {
        return app(OrderService::class)->create($customer, [['variant_id' => $this->variant->id, 'quantity' => $quantity]], PaymentMethod::BankTransfer, OrderSource::Admin);
    }

    private function placeOrder(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/customer/orders', array_merge([
            'items' => [['variant_id' => $this->variant->id, 'quantity' => 3]],
            'payment_method' => 'bank_transfer',
            'request_id' => (string) Str::uuid(),
        ], $overrides));
    }

    // ── 權限 ────────────────────────────────────────────

    public function test_customer_api_requires_customer_login(): void
    {
        auth('customer')->logout();
        $this->getJson('/api/customer/me')->assertUnauthorized();
    }

    public function test_staff_session_cannot_use_customer_api(): void
    {
        auth('customer')->logout();
        $this->actingAs(User::factory()->create(), 'web');

        $this->getJson('/api/customer/orders')->assertUnauthorized();
    }

    public function test_cannot_view_or_print_another_customers_order(): void
    {
        $theirs = $this->orderFor($this->other);

        $this->getJson("/api/customer/orders/{$theirs->id}")->assertNotFound();
        $this->get("/api/customer/orders/{$theirs->id}/pdf")->assertNotFound();
        $this->assertStringNotContainsString('別家水電行', $this->getJson('/api/customer/orders')->getContent());
        $this->assertStringNotContainsString('別家水電行', $this->getJson('/api/customer/recent-orders')->getContent());
    }

    public function test_cannot_confirm_another_customers_reservation(): void
    {
        $theirs = app(ReservationService::class)->create($this->other, $this->variant->id, 5, now()->addDays(10));

        $this->postJson("/api/customer/reservations/{$theirs->id}/confirm", ['request_id' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame(ReservationStatus::Active, $theirs->fresh()->status);
        $this->getJson('/api/customer/reservations')->assertJsonCount(0, 'data');
    }

    public function test_cannot_read_another_customers_notifications(): void
    {
        $theirs = app(CustomerNotifier::class)->notify($this->other, 'test', '別人的通知', '內容');

        $this->getJson('/api/customer/notifications')->assertJsonCount(0, 'data');
        $this->postJson("/api/customer/notifications/{$theirs->id}/read")->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_customer_id_in_request_body_is_ignored(): void
    {
        $this->placeOrder(['customer_id' => $this->other->id])->assertCreated();

        $this->assertSame(0, $this->other->orders()->count());
        $this->assertSame(1, $this->me->orders()->count());
    }

    // ── 功能 ────────────────────────────────────────────

    public function test_me_returns_profile_and_unread_count(): void
    {
        app(CustomerNotifier::class)->notify($this->me, 'test', '標題', '內容');

        $this->getJson('/api/customer/me')
            ->assertOk()
            ->assertJsonPath('data.name', '我的水電行')
            ->assertJsonPath('data.unread_notifications', 1);
    }

    public function test_catalog_shows_stock_status_without_exact_quantities(): void
    {
        $low = ProductVariant::factory()->withStock(3)->create();
        $out = ProductVariant::factory()->withStock(0)->create();
        ProductVariant::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/customer/products')->assertOk();

        $variants = collect($response->json('data'))->flatMap(fn ($p) => $p['variants'])->keyBy('id');
        $this->assertCount(3, $variants, '停售規格不顯示');
        $this->assertSame('in_stock', $variants[$this->variant->id]['stock_status']);
        $this->assertSame('low', $variants[$low->id]['stock_status']);
        $this->assertSame('out', $variants[$out->id]['stock_status']);
        $this->assertArrayNotHasKey('stock', $variants[$this->variant->id]);
        $this->assertArrayNotHasKey('available', $variants[$this->variant->id]);
    }

    public function test_place_order_creates_pending_customer_order(): void
    {
        $this->placeOrder(['note' => '早上送'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_label', '待確認')
            ->assertJsonPath('data.total_amount', 150)
            ->assertJsonPath('data.source', 'customer');

        $this->assertNull(Order::sole()->created_by);
    }

    public function test_repeated_submission_with_same_request_id_creates_one_order(): void
    {
        $requestId = (string) Str::uuid();

        $first = $this->placeOrder(['request_id' => $requestId])->assertCreated()->json('data.id');
        $second = $this->placeOrder(['request_id' => $requestId])->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::count());
        $this->assertSame(3, $this->variant->inventory()->first()->allocated, '重複送出不可重複佔用庫存');
    }

    public function test_request_id_is_required(): void
    {
        $this->placeOrder(['request_id' => null])->assertUnprocessable()->assertJsonValidationErrors('request_id');
    }

    public function test_insufficient_stock_message_is_understandable(): void
    {
        $this->placeOrder(['items' => [['variant_id' => $this->variant->id, 'quantity' => 101]]])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($message) => str_contains($message, $this->variant->product->name) && str_contains($message, '100'));
    }

    public function test_list_and_view_own_orders_with_payment_status(): void
    {
        $order = $this->orderFor($this->me, 4);

        $this->getJson('/api/customer/orders')
            ->assertOk()
            ->assertJsonPath('data.0.order_no', $order->order_no)
            ->assertJsonPath('data.0.payment.status_label', '未收');
        $this->getJson("/api/customer/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 4)
            ->assertJsonMissingPath('data.created_by');
    }

    public function test_print_own_order_pdf_is_logged_as_customer(): void
    {
        $order = $this->orderFor($this->me);

        $this->get("/api/customer/orders/{$order->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame($this->me->id, $order->prints()->sole()->customer_id);
    }

    public function test_recent_orders_for_reorder(): void
    {
        $this->orderFor($this->me, 5);
        $this->variant->update(['price' => 60]);

        $this->getJson('/api/customer/recent-orders')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.unit_price', 50)
            ->assertJsonPath('data.0.items.0.current.price', 60)
            ->assertJsonPath('data.0.items.0.current.orderable', true)
            ->assertJsonMissingPath('data.0.items.0.current.available');
    }

    public function test_confirm_reservation_places_order_using_it(): void
    {
        $reservation = app(ReservationService::class)->create($this->me, $this->variant->id, 8, now()->addDays(5));

        $this->getJson('/api/customer/reservations')->assertJsonPath('data.0.id', $reservation->id);
        $orderId = $this->postJson("/api/customer/reservations/{$reservation->id}/confirm", [
            'payment_method' => 'cash_on_delivery',
            'request_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Fulfilled, $reservation->status);
        $this->assertSame($orderId, $reservation->order_id);
        $this->assertSame(8, Order::find($orderId)->items->sole()->quantity);
        $this->assertSame(OrderStatus::Pending, Order::find($orderId)->status);
    }

    public function test_confirming_an_expired_reservation_fails(): void
    {
        $reservation = app(ReservationService::class)->create($this->me, $this->variant->id, 8, now()->addDay());
        $this->travel(2)->days();
        app(ReservationService::class)->expireDue();

        $this->postJson("/api/customer/reservations/{$reservation->id}/confirm", ['request_id' => (string) Str::uuid()])
            ->assertUnprocessable();
    }

    public function test_notifications_list_and_mark_read(): void
    {
        $notice = app(CustomerNotifier::class)->notify($this->me, 'test', '您的訂單已確認', '內容');

        $this->getJson('/api/customer/notifications')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', '您的訂單已確認')
            ->assertJsonPath('data.0.read', false);
        $this->postJson("/api/customer/notifications/{$notice->id}/read")->assertOk();
        $this->assertNotNull($notice->fresh()->read_at);

        app(CustomerNotifier::class)->notify($this->me, 'test', 'A', 'x');
        app(CustomerNotifier::class)->notify($this->me, 'test', 'B', 'x');
        $this->postJson('/api/customer/notifications/read-all')->assertOk();
        $this->assertSame(0, NotificationLog::where('customer_id', $this->me->id)->where('channel', 'site')->whereNull('read_at')->count());
    }
}
