<?php

namespace Tests\Feature\Reports;

use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Customer $alpha;

    private Customer $beta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-09-20 10:00'));
        $this->actingAs(User::factory()->create(), 'web');
        $this->alpha = Customer::factory()->create(['name' => '甲水電']);
        $this->beta = Customer::factory()->create(['name' => '乙水電']);
    }

    /** 在指定時間建單、確認、出貨；可指定收款時間 */
    private function shipped(Customer $customer, int $amount, string $shippedAt, ?string $paidAt = null, string $product = 'PVC 水管'): Order
    {
        $service = app(OrderService::class);
        $variant = ProductVariant::factory()->withStock(1000)->create(['price' => $amount]);
        $variant->product->update(['name' => $product]);
        $now = now();

        $this->travelTo(Carbon::parse($shippedAt)->subDay());
        $order = $service->create($customer, [['variant_id' => $variant->id, 'quantity' => 1]], PaymentMethod::BankTransfer, OrderSource::Admin);
        $service->confirm($order);
        $this->travelTo(Carbon::parse($shippedAt));
        $order = $service->ship($order);
        if ($paidAt) {
            $this->travelTo(Carbon::parse($paidAt));
            app(PaymentService::class)->markPaid([$order->payment->id], ['method' => PaymentMethod::BankTransfer]);
        }
        $this->travelTo($now);

        return $order;
    }

    public function test_summary_figures(): void
    {
        $this->shipped($this->alpha, 1000, '2026-09-05 10:00', paidAt: '2026-09-10 10:00');
        $this->shipped($this->alpha, 2000, '2026-09-12 10:00');
        $this->shipped($this->beta, 500, '2026-08-15 10:00', paidAt: '2026-09-02 10:00');
        $this->shipped($this->beta, 300, '2026-08-20 10:00');
        $variant = ProductVariant::factory()->withStock(5)->create();
        app(OrderService::class)->create($this->alpha, [['variant_id' => $variant->id, 'quantity' => 1]], PaymentMethod::BankTransfer, OrderSource::Customer);

        $this->getJson('/api/admin/dashboard?months=6')
            ->assertOk()
            ->assertJsonPath('data.summary.receivable', 2300)
            ->assertJsonPath('data.summary.shipped_this_month', 3000)
            ->assertJsonPath('data.summary.shipped_last_month', 800)
            ->assertJsonPath('data.summary.collected_this_month', 1500)
            ->assertJsonPath('data.summary.pending_orders', 1)
            ->assertJsonPath('data.summary.low_stock_variants', 1);
    }

    public function test_monthly_trend_groups_by_taipei_month_and_fills_empty_months(): void
    {
        $this->shipped($this->alpha, 1000, '2026-09-01 07:30', paidAt: '2026-09-01 07:45'); // 此時刻換算 UTC 仍是 8/31，須以台北時間歸入 9 月
        $this->shipped($this->alpha, 400, '2026-07-10 10:00');

        $trend = $this->getJson('/api/admin/dashboard?months=3')->json('data.trend');

        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_column($trend, 'month'));
        $this->assertSame([400, 0, 1000], array_column($trend, 'shipped'));
        $this->assertSame([0, 0, 1000], array_column($trend, 'collected'));
    }

    public function test_receivable_aging_buckets_by_days_since_shipping(): void
    {
        $this->shipped($this->alpha, 100, '2026-09-10 10:00');  // 10 天
        $this->shipped($this->alpha, 200, '2026-08-01 10:00');  // 50 天
        $this->shipped($this->beta, 400, '2026-07-01 10:00');   // 81 天
        $this->shipped($this->beta, 800, '2026-05-01 10:00');   // 142 天
        $this->shipped($this->beta, 999, '2026-05-01 10:00', paidAt: '2026-05-05 10:00');

        $aging = $this->getJson('/api/admin/dashboard')->json('data.aging');

        $this->assertSame(['0–30 天', '31–60 天', '61–90 天', '90 天以上'], array_column($aging, 'label'));
        $this->assertSame([100, 200, 400, 800], array_column($aging, 'amount'));
        $this->assertSame([1, 1, 1, 1], array_column($aging, 'orders'));
    }

    public function test_top_receivable_customers_with_oldest_unpaid(): void
    {
        $this->shipped($this->alpha, 300, '2026-09-10 10:00');
        $this->shipped($this->beta, 500, '2026-08-01 10:00');
        $this->shipped($this->beta, 400, '2026-09-15 10:00');

        $this->getJson('/api/admin/dashboard')
            ->assertJsonPath('data.top_receivables.0.customer_name', '乙水電')
            ->assertJsonPath('data.top_receivables.0.amount', 900)
            ->assertJsonPath('data.top_receivables.0.orders', 2)
            ->assertJsonPath('data.top_receivables.0.oldest_days', 50)
            ->assertJsonPath('data.top_receivables.1.customer_name', '甲水電');
    }

    public function test_top_products_within_period_by_shipped_amount(): void
    {
        $this->shipped($this->alpha, 900, '2026-09-10 10:00', product: '漏電斷路器');
        $this->shipped($this->beta, 300, '2026-08-10 10:00', product: 'LED 燈管');
        $this->shipped($this->beta, 5000, '2026-01-10 10:00', product: '很久以前的商品');

        $products = $this->getJson('/api/admin/dashboard?months=6')->json('data.top_products');

        $this->assertSame(['漏電斷路器', 'LED 燈管'], array_column($products, 'product_name'));
        $this->assertSame(900, $products[0]['amount']);
    }

    public function test_gross_profit_this_month_with_cost_coverage(): void
    {
        // 有成本：售 1000、成本 600 → 毛利 400
        $costed = ProductVariant::factory()->withStock(10)->create(['price' => 1000, 'avg_cost' => 600]);
        $service = app(OrderService::class);
        $order = $service->create($this->alpha, [['variant_id' => $costed->id, 'quantity' => 1]], PaymentMethod::BankTransfer, OrderSource::Admin);
        $service->confirm($order);
        $service->ship($order->fresh());
        // 無成本資料（第一階段的舊商品）：售 1000
        $this->shipped($this->beta, 1000, '2026-09-10 10:00');

        $this->getJson('/api/admin/dashboard')
            ->assertJsonPath('data.summary.gross_profit_this_month', 400)
            ->assertJsonPath('data.summary.gross_margin_rate', 0.4)
            ->assertJsonPath('data.summary.cost_coverage', 0.5);
    }

    public function test_months_parameter_is_limited(): void
    {
        $this->getJson('/api/admin/dashboard?months=99')->assertUnprocessable();
    }

    public function test_requires_staff_login(): void
    {
        auth('web')->logout();
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }
}
