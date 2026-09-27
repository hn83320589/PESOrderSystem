<?php

namespace Tests\Feature\EndToEnd;

use App\Enums\NotificationChannel;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Customer\LineAuthController;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\TrialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 以試用資料（TrialSeeder）逐一走過 docs/TRIAL_SCRIPT.md 的情境 S1–S8。
 * 劇本與程式脫鉤時此測試會失敗，提醒同步更新劇本。
 */
class TrialScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(TrialSeeder::class);
    }

    /**
     * 正式環境每個請求都是全新的 app，預設 guard 為 web；auth:customer 驗證成功時會把預設 guard
     * 切成 customer，而測試中 app 跨請求共用，這個切換會殘留到下一個請求，使 Sanctum 的
     * AuthenticateSession 拿客戶（無密碼）比對工讀生的密碼雜湊而整個登出。每個請求前還原以貼近正式環境。
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        Auth::shouldUse('web');

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function customer(string $name): Customer
    {
        return Customer::where('name', $name)->sole();
    }

    // 管理端與客戶端 API 各自只認自己的 guard，切換角色不需登出（guard 隔離另有專門測試）
    private function asStaff(string $email = TrialSeeder::STAFF_EMAIL): void
    {
        $this->actingAs(User::where('email', $email)->sole(), 'web');
    }

    /** 只設定 customer guard，不用 actingAs（actingAs 同樣會切換預設 guard，原因見 call()） */
    private function asCustomer(Customer $customer): void
    {
        Auth::guard('customer')->setUser($customer);
        $this->withSession([LineAuthController::SESSION_LINE_SUB => $customer->line_user_id]);
    }

    public function test_trial_data_is_ready(): void
    {
        $this->assertSame(3, User::count());
        $this->assertNotNull($this->customer(TrialSeeder::MONTHLY_CUSTOMER)->line_user_id);
        $this->assertNotNull($this->customer(TrialSeeder::COD_CUSTOMER)->line_user_id);
        $unbound = $this->customer(TrialSeeder::UNBOUND_CUSTOMER);
        $this->assertNull($unbound->line_user_id);
        $this->assertTrue($unbound->line_bind_token_expires_at->isFuture(), 'S4 需要有效的綁定連結');
        $this->assertSame(TrialSeeder::LOW_STOCK_QUANTITY, $this->lowStockVariant()->inventory->available());
    }

    /** S1 工讀生用老樣子代客下單 → 確認 → 列印 → 出貨 */
    public function test_s1_staff_reorders_for_customer_and_ships(): void
    {
        $this->asStaff();
        $customer = $this->customer(TrialSeeder::MONTHLY_CUSTOMER);

        $recent = $this->getJson("/api/admin/customers/{$customer->id}/recent-orders")->assertOk()->json('data');
        $this->assertNotEmpty($recent);
        $items = collect($recent[0]['items'])->map(fn ($i) => ['variant_id' => $i['variant_id'], 'quantity' => $i['quantity']])->all();

        $id = $this->postJson('/api/admin/orders', ['customer_id' => $customer->id, 'payment_method' => 'bank_transfer', 'items' => $items])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/admin/orders/{$id}/confirm")->assertOk();
        $this->get("/api/admin/orders/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/admin/orders/{$id}/ship")->assertOk()->assertJsonPath('data.status', 'shipped');

        $this->assertTrue(NotificationLog::where('related_id', $id)->where('channel', NotificationChannel::Line)->exists(), '確認時要留下 LINE 通知紀錄');
    }

    /** S2 熟客用手機「照上次叫的貨」→ 店家確認 → 熟客看到已確認與通知 */
    public function test_s2_customer_reorders_and_sees_confirmation(): void
    {
        $customer = $this->customer(TrialSeeder::COD_CUSTOMER);
        $this->asCustomer($customer);

        $recent = $this->getJson('/api/customer/recent-orders')->assertOk()->json('data.0.items');
        $id = $this->postJson('/api/customer/orders', [
            'items' => collect($recent)->map(fn ($i) => ['variant_id' => $i['variant_id'], 'quantity' => $i['quantity']])->all(),
            'request_id' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('data.payment_method', 'cash_on_delivery')->json('data.id');

        $this->asStaff();
        $this->getJson('/api/admin/orders?status=pending')->assertJsonFragment(['id' => $id, 'source' => 'customer']);
        $this->postJson("/api/admin/orders/{$id}/confirm")->assertOk();

        $this->asCustomer($customer->fresh());
        $this->getJson("/api/customer/orders/{$id}")->assertJsonPath('data.status', 'confirmed');
        $this->assertGreaterThanOrEqual(1, $this->getJson('/api/customer/me')->json('data.unread_notifications'));
    }

    /** S3 熟客確認店家預留的貨 */
    public function test_s3_customer_confirms_reservation(): void
    {
        $customer = $this->customer(TrialSeeder::MONTHLY_CUSTOMER);
        $this->asCustomer($customer);

        $reservation = $this->getJson('/api/customer/reservations')->assertJsonCount(1, 'data')->json('data.0');
        $this->postJson("/api/customer/reservations/{$reservation['id']}/confirm", ['request_id' => (string) Str::uuid()])->assertCreated();

        $this->assertSame(ReservationStatus::Fulfilled, Reservation::find($reservation['id'])->status);
    }

    /** S4 未開通客戶：後台有可用的綁定連結（實際綁定需 LINE Login 金鑰） */
    public function test_s4_unbound_customer_has_bind_link_and_cannot_log_in(): void
    {
        $this->asStaff();
        $customer = $this->customer(TrialSeeder::UNBOUND_CUSTOMER);

        $this->getJson('/api/admin/customers?'.http_build_query(['q' => TrialSeeder::UNBOUND_CUSTOMER]))
            ->assertJsonPath('data.0.line_bound', false);
        $this->postJson("/api/admin/customers/{$customer->id}/line-bind-link")->assertOk()
            ->assertJsonPath('data.url', fn ($url) => str_contains($url, '/line/bind/'));
    }

    /** S5 庫存不足時客戶看得懂的訊息 */
    public function test_s5_insufficient_stock_is_explained(): void
    {
        $this->asCustomer($this->customer(TrialSeeder::COD_CUSTOMER));
        $variant = $this->lowStockVariant();

        $this->postJson('/api/customer/orders', [
            'items' => [['variant_id' => $variant->id, 'quantity' => TrialSeeder::LOW_STOCK_QUANTITY + 3]],
            'request_id' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, $variant->product->name) && str_contains($m, (string) TrialSeeder::LOW_STOCK_QUANTITY));

        $this->getJson('/api/customer/products')->assertJsonFragment(['id' => $variant->id, 'stock_status' => 'low']);
    }

    /** S6 老闆月結：上個月未收 → 批次收款 → 匯出 Excel */
    public function test_s6_boss_collects_last_months_payments(): void
    {
        $this->asStaff(TrialSeeder::BOSS_EMAIL);
        $customer = $this->customer(TrialSeeder::MONTHLY_CUSTOMER);
        $month = now()->subMonthNoOverflow()->format('Y-m');

        $row = collect($this->getJson("/api/admin/reports/monthly?month={$month}")->json('data.rows'))->firstWhere('customer_id', $customer->id);
        $this->assertGreaterThan(0, $row['unpaid_total'], '試用資料需有上月未收款');

        // API 時間為 UTC，需轉回台北時間再判斷月份
        $unpaid = collect($this->getJson("/api/admin/payments?status=unpaid&customer_id={$customer->id}")->json('data'))
            ->filter(fn ($p) => $p['order']['shipped_at']
                && Carbon::parse($p['order']['shipped_at'])->setTimezone(config('app.timezone'))->format('Y-m') === $month);
        $this->postJson('/api/admin/payments/bulk-mark-paid', [
            'payment_ids' => $unpaid->pluck('id')->all(), 'method' => 'bank_transfer', 'note' => '試用：上月貨款',
        ])->assertOk()->assertJsonPath('data.amount', $row['unpaid_total']);

        $after = collect($this->getJson("/api/admin/reports/monthly?month={$month}")->json('data.rows'))->firstWhere('customer_id', $customer->id);
        $this->assertSame(0, $after['unpaid_total']);
        $this->get("/api/admin/reports/monthly/export?month={$month}")->assertOk();
    }

    /** S7 排程：到期預留釋放、7 天內到期提醒 */
    public function test_s7_scheduled_jobs_release_and_remind(): void
    {
        $due = Reservation::where('customer_id', $this->customer(TrialSeeder::COD_CUSTOMER)->id)->sole();
        $available = $due->variant->inventory->available();

        $this->artisan('reservations:expire')->assertSuccessful();
        $this->artisan('reservations:remind')->assertSuccessful();

        $this->assertSame(ReservationStatus::Expired, $due->fresh()->status);
        $this->assertSame($available + $due->quantity, $due->variant->inventory()->first()->available());
        $soon = Reservation::where('customer_id', $this->customer(TrialSeeder::MONTHLY_CUSTOMER)->id)->sole();
        $this->assertNotNull($soon->fresh()->reminded_at);
    }

    /** S8 後台解除綁定後，客戶手機上的登入立即失效 */
    public function test_s8_unbinding_logs_customer_out(): void
    {
        $customer = $this->customer(TrialSeeder::COD_CUSTOMER);
        $phoneSessionLineId = $customer->line_user_id;
        $this->asCustomer($customer);
        $this->getJson('/api/customer/me')->assertOk();

        $this->asStaff();
        $this->deleteJson("/api/admin/customers/{$customer->id}/line-binding")->assertOk();

        // 手機上的 session 仍記著舊的 LINE userId，但資料庫已解除綁定
        Auth::guard('customer')->setUser($customer->fresh());
        $this->withSession([LineAuthController::SESSION_LINE_SUB => $phoneSessionLineId])
            ->getJson('/api/customer/me')->assertUnauthorized()->assertJsonPath('message', '請重新登入');
    }

    /** 試用資料的訂單都經由正式流程建立：庫存與流水帳一致 */
    public function test_seeded_inventory_matches_open_orders(): void
    {
        foreach (ProductVariant::with('inventory')->get() as $variant) {
            $openQuantity = Order::whereIn('status', [OrderStatus::Pending, OrderStatus::Confirmed])
                ->join('order_items', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.product_variant_id', $variant->id)
                ->sum('order_items.quantity');
            $this->assertSame((int) $openQuantity, $variant->inventory->allocated, "規格 {$variant->id} 的已佔用量需等於未出貨訂單數量");
        }
        $this->assertTrue(Order::where('source', OrderSource::Customer)->where('status', OrderStatus::Pending)->exists(), '需有一張客戶送出、待確認的訂單');
    }

    private function lowStockVariant(): ProductVariant
    {
        return ProductVariant::with('inventory', 'product')
            ->whereRelation('product', 'name', TrialSeeder::LOW_STOCK_PRODUCT)
            ->where('spec', TrialSeeder::LOW_STOCK_SPEC)
            ->sole();
    }
}
