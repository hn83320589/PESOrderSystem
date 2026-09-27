<?php

namespace Tests\Feature\Payments;

use App\Enums\BillingType;
use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exports\MonthlyStatementExport;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    private Customer $monthly;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(), 'web');
        $this->monthly = Customer::factory()->create(['name' => '月結水電行', 'billing_type' => BillingType::Monthly]);
    }

    private function shippedOrder(Customer $customer, int $amount, string $shippedAt, bool $paid = false): Order
    {
        $service = app(OrderService::class);
        $variant = ProductVariant::factory()->withStock(1000)->create(['price' => $amount]);
        $this->travelTo(Carbon::parse($shippedAt)->subDay());
        $order = $service->create($customer, [['variant_id' => $variant->id, 'quantity' => 1]], PaymentMethod::BankTransfer, OrderSource::Admin);
        $service->confirm($order);
        $this->travelTo(Carbon::parse($shippedAt));
        $order = $service->ship($order);
        if ($paid) {
            $order->payment->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        }
        $this->travelBack();

        return $order;
    }

    public function test_summary_groups_shipped_orders_by_shipping_month(): void
    {
        $this->shippedOrder($this->monthly, 1000, '2026-09-05 10:00', paid: true);
        $this->shippedOrder($this->monthly, 2000, '2026-09-20 10:00');
        $this->shippedOrder($this->monthly, 500, '2026-08-25 10:00');           // 前期未收
        $this->shippedOrder($this->monthly, 700, '2026-08-10 10:00', paid: true); // 前期已收，不計
        $this->shippedOrder($this->monthly, 9999, '2026-10-01 00:30');          // 下個月（台北時間）不計

        $this->getJson('/api/admin/reports/monthly?month=2026-09')
            ->assertOk()
            ->assertJsonPath('data.rows.0.customer_name', '月結水電行')
            ->assertJsonPath('data.rows.0.order_count', 2)
            ->assertJsonPath('data.rows.0.shipped_total', 3000)
            ->assertJsonPath('data.rows.0.paid_total', 1000)
            ->assertJsonPath('data.rows.0.unpaid_total', 2000)
            ->assertJsonPath('data.rows.0.previous_unpaid', 500)
            ->assertJsonPath('data.rows.0.outstanding', 2500)
            ->assertJsonPath('data.totals.shipped_total', 3000);
    }

    public function test_unshipped_and_expired_orders_are_not_billed(): void
    {
        $variant = ProductVariant::factory()->withStock(100)->create(['price' => 100]);
        $service = app(OrderService::class);
        $service->create($this->monthly, [['variant_id' => $variant->id, 'quantity' => 1]], PaymentMethod::BankTransfer, OrderSource::Admin);
        $expired = $service->create($this->monthly, [['variant_id' => $variant->id, 'quantity' => 1]], PaymentMethod::BankTransfer, OrderSource::Admin);
        $service->expire($expired);

        $this->getJson('/api/admin/reports/monthly?month='.now()->format('Y-m'))
            ->assertOk()->assertJsonCount(0, 'data.rows');
    }

    public function test_filter_by_billing_type(): void
    {
        $cod = Customer::factory()->create(['billing_type' => BillingType::CashOnDelivery]);
        $this->shippedOrder($this->monthly, 100, '2026-09-05 10:00');
        $this->shippedOrder($cod, 100, '2026-09-05 10:00');

        $this->getJson('/api/admin/reports/monthly?month=2026-09&billing_type=monthly')
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.customer_id', $this->monthly->id);
    }

    public function test_customer_detail_lists_orders_and_items(): void
    {
        $order = $this->shippedOrder($this->monthly, 1234, '2026-09-05 10:00');

        $this->getJson("/api/admin/reports/monthly/{$this->monthly->id}?month=2026-09")
            ->assertOk()
            ->assertJsonPath('data.orders.0.order_no', $order->order_no)
            ->assertJsonPath('data.orders.0.items.0.subtotal', 1234)
            ->assertJsonPath('data.summary.shipped_total', 1234);
    }

    public function test_month_is_required_and_validated(): void
    {
        $this->getJson('/api/admin/reports/monthly?month=2026-13')->assertUnprocessable()->assertJsonValidationErrors('month');
    }

    public function test_exported_file_keeps_zero_amounts_and_totals(): void
    {
        $this->shippedOrder($this->monthly, 1000, '2026-09-05 10:00', paid: true);
        $this->shippedOrder(Customer::factory()->create(['name' => '乙水電']), 500, '2026-09-06 10:00');

        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, Excel::raw(new MonthlyStatementExport(ReportService::monthStart('2026-09')), \Maatwebsite\Excel\Excel::XLSX));
        $sheet = IOFactory::load($file)->getSheet(0);
        unlink($file);

        // 資料列依客戶名稱排序：乙水電、月結水電行
        $this->assertSame('月結水電行', $sheet->getCell('A3')->getValue());
        $this->assertSame(0, $sheet->getCell('F3')->getValue(), '已收清的本期未收要顯示 0，不可是空白');
        $this->assertSame(1500, $sheet->getCell('D4')->getCalculatedValue(), '合計列以公式加總');
        $this->assertStringStartsWith('=SUM(', $sheet->getCell('D4')->getValue());
    }

    public function test_export_downloads_xlsx(): void
    {
        Excel::fake();
        $this->shippedOrder($this->monthly, 1000, '2026-09-05 10:00');

        $this->get('/api/admin/reports/monthly/export?month=2026-09')->assertOk();

        Excel::assertDownloaded('月結對帳_2026-09.xlsx', function ($export) {
            $sheets = $export->sheets();
            $summary = $sheets[0]->array();

            return count($sheets) === 2
                && $summary[0][0] === '月結水電行'
                && $sheets[1]->array()[0][2] !== null;
        });
    }
}
