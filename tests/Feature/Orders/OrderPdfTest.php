<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPrint;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderPdfService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['shop.name' => '大明水電材料行', 'shop.bank.name' => '台灣銀行', 'shop.bank.account' => '123-456-789012']);
        $this->staff = User::factory()->create();
        $this->actingAs($this->staff, 'web');
    }

    private function order(PaymentMethod $method = PaymentMethod::BankTransfer): Order
    {
        $variant = ProductVariant::factory()->withStock(100)->create(['price' => 60, 'spec' => '1/2"']);

        return app(OrderService::class)->create(
            Customer::factory()->create(['name' => '測試水電行']),
            [['variant_id' => $variant->id, 'quantity' => 10]],
            $method,
            OrderSource::Admin,
            note: '下午送',
        );
    }

    public function test_pdf_is_generated_when_order_is_created(): void
    {
        $order = $this->order()->fresh();

        $this->assertNotNull($order->pdf_path);
        Storage::disk('local')->assertExists($order->pdf_path);
        $pdf = Storage::disk('local')->get($order->pdf_path);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('NotoSansTC', $pdf, '中文字型必須嵌入 PDF');
    }

    public function test_document_content_includes_order_details_and_bank_account(): void
    {
        $order = $this->order();

        $html = app(OrderPdfService::class)->renderHtml($order);

        foreach ([$order->order_no, '測試水電行', $order->items[0]->product_name, '1/2&quot;', '$600', '匯款', '123-456-789012', '下午送', '大明水電材料行'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    public function test_bank_account_is_omitted_for_cash_orders(): void
    {
        $html = app(OrderPdfService::class)->renderHtml($this->order(PaymentMethod::CashOnDelivery));

        $this->assertStringNotContainsString('123-456-789012', $html);
    }

    public function test_editing_the_order_regenerates_the_pdf(): void
    {
        $order = $this->order()->fresh();
        $before = Storage::disk('local')->get($order->pdf_path);

        app(OrderService::class)->update($order, [['variant_id' => $order->items[0]->product_variant_id, 'quantity' => 20]]);

        $this->assertNotSame($before, Storage::disk('local')->get($order->fresh()->pdf_path));
    }

    public function test_reprint_returns_identical_document_and_logs_each_print(): void
    {
        $order = $this->order();

        $first = $this->get("/api/admin/orders/{$order->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // 列印後才改店家帳號設定，補印仍需與原單一致
        config(['shop.bank.account' => '999-999-999999']);
        $second = $this->get("/api/admin/orders/{$order->id}/pdf")->assertOk();

        $this->assertSame($first->getContent(), $second->getContent(), '補印內容必須與原單完全相同');
        $prints = OrderPrint::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertSame([false, true], $prints->pluck('is_reprint')->all());
        $this->assertSame($this->staff->id, $prints[0]->user_id);
    }

    public function test_missing_file_is_regenerated_on_print(): void
    {
        $order = $this->order()->fresh();
        Storage::disk('local')->delete($order->pdf_path);

        $this->get("/api/admin/orders/{$order->id}/pdf")->assertOk();

        Storage::disk('local')->assertExists($order->fresh()->pdf_path);
    }

    public function test_pdf_requires_staff_login(): void
    {
        $order = $this->order();
        auth('web')->logout();

        $this->getJson("/api/admin/orders/{$order->id}/pdf")->assertUnauthorized();
    }

    public function test_order_resource_reports_print_count(): void
    {
        $order = $this->order();
        $this->get("/api/admin/orders/{$order->id}/pdf");
        $this->get("/api/admin/orders/{$order->id}/pdf");

        $this->getJson("/api/admin/orders/{$order->id}")->assertJsonPath('data.print_count', 2);
    }
}
