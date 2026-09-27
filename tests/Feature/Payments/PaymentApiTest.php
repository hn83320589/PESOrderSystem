<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create();
        $this->actingAs($this->staff, 'web');
        $this->customer = Customer::factory()->create();
    }

    private function order(int $amount = 100, ?Customer $customer = null): Order
    {
        $variant = ProductVariant::factory()->withStock(1000)->create(['price' => $amount]);

        return app(OrderService::class)->create($customer ?? $this->customer, [['variant_id' => $variant->id, 'quantity' => 1]], PaymentMethod::BankTransfer, OrderSource::Admin);
    }

    public function test_list_unpaid_payments_with_order_info(): void
    {
        $order = $this->order(300);
        $this->order(200, Customer::factory()->create());

        $this->getJson("/api/admin/payments?status=unpaid&customer_id={$this->customer->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', 300)
            ->assertJsonPath('data.0.order.order_no', $order->order_no)
            ->assertJsonPath('data.0.customer.name', $this->customer->name);
    }

    public function test_expired_orders_are_excluded_from_payments(): void
    {
        $order = $this->order();
        app(OrderService::class)->expire($order);

        $this->getJson('/api/admin/payments?status=unpaid')->assertJsonCount(0, 'data');
    }

    public function test_mark_paid_records_method_time_and_staff(): void
    {
        $payment = $this->order(500)->payment;

        $this->postJson("/api/admin/payments/{$payment->id}/mark-paid", [
            'method' => 'cash_on_delivery',
            'paid_at' => '2026-09-20 15:00',
            'note' => '司機收現',
        ])->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.status_label', '已收')
            ->assertJsonPath('data.method_label', '現金貨到付款');

        $payment->refresh();
        $this->assertSame('2026-09-20 15:00', $payment->paid_at->format('Y-m-d H:i'));
        $this->assertSame($this->staff->id, $payment->recorded_by);
        $this->assertSame('司機收現', $payment->note);
    }

    public function test_check_payment_requires_check_number_and_due_date(): void
    {
        $payment = $this->order()->payment;

        $this->postJson("/api/admin/payments/{$payment->id}/mark-paid", ['method' => 'check'])
            ->assertUnprocessable()->assertJsonValidationErrors(['check_no', 'check_due_date']);

        $this->postJson("/api/admin/payments/{$payment->id}/mark-paid", [
            'method' => 'check', 'check_no' => 'AB1234567', 'check_due_date' => '2026-11-30',
        ])->assertOk()->assertJsonPath('data.check_no', 'AB1234567');
    }

    public function test_cannot_mark_paid_twice_or_for_expired_order(): void
    {
        $payment = $this->order()->payment;
        $this->postJson("/api/admin/payments/{$payment->id}/mark-paid", ['method' => 'bank_transfer'])->assertOk();
        $this->postJson("/api/admin/payments/{$payment->id}/mark-paid", ['method' => 'bank_transfer'])->assertUnprocessable();

        $expired = $this->order();
        app(OrderService::class)->expire($expired);
        $this->postJson("/api/admin/payments/{$expired->payment->id}/mark-paid", ['method' => 'bank_transfer'])->assertUnprocessable();
    }

    public function test_bulk_mark_paid_is_all_or_nothing(): void
    {
        $a = $this->order(100)->payment;
        $b = $this->order(200)->payment;
        $alreadyPaid = $this->order(300)->payment;
        $alreadyPaid->update(['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        $this->postJson('/api/admin/payments/bulk-mark-paid', [
            'payment_ids' => [$a->id, $b->id, $alreadyPaid->id], 'method' => 'bank_transfer',
        ])->assertUnprocessable();
        $this->assertSame(PaymentStatus::Unpaid, $a->fresh()->status, '有一筆失敗就全部不處理');

        $this->postJson('/api/admin/payments/bulk-mark-paid', [
            'payment_ids' => [$a->id, $b->id], 'method' => 'bank_transfer', 'note' => '9月月結匯款',
        ])->assertOk()->assertJsonPath('data.count', 2)->assertJsonPath('data.amount', 300);
        $this->assertSame(PaymentStatus::Paid, $b->fresh()->status);
    }

    public function test_mark_unpaid_reverts_a_mistake(): void
    {
        $payment = $this->order()->payment;
        $this->postJson("/api/admin/payments/{$payment->id}/mark-paid", ['method' => 'check', 'check_no' => 'X1', 'check_due_date' => '2026-12-01']);

        $this->postJson("/api/admin/payments/{$payment->id}/mark-unpaid")->assertOk()->assertJsonPath('data.status', 'unpaid');

        $payment->refresh();
        $this->assertNull($payment->paid_at);
        $this->assertNull($payment->check_no);
    }
}
