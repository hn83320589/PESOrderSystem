<?php

namespace App\Services;

use App\Enums\BillingType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 月結對帳。訂單依「出貨日」歸屬月份（台北時間），只計已出貨訂單。
 */
class ReportService
{
    /** 'Y-m' → 該月第一天 00:00（'!' 讓未指定欄位歸零，避免月底解析時跳到下個月） */
    public static function monthStart(string $month): Carbon
    {
        return Carbon::createFromFormat('!Y-m', $month);
    }

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function monthlySummary(Carbon $monthStart, ?BillingType $billingType = null): array
    {
        $monthEnd = $monthStart->copy()->endOfMonth();

        $orders = $this->shippedOrders($monthStart, $monthEnd)
            ->when($billingType, fn ($q) => $q->whereRelation('customer', 'billing_type', $billingType))
            ->with('customer', 'payment')
            ->get()
            ->groupBy('customer_id');

        // 本月之前出貨、至今仍未收的金額
        $previousUnpaid = Payment::query()
            ->where('status', PaymentStatus::Unpaid)
            ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Shipped)->where('shipped_at', '<', $monthStart))
            ->when($billingType, fn ($q) => $q->whereRelation('customer', 'billing_type', $billingType))
            ->selectRaw('customer_id, SUM(amount) as total')
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id');

        $customerIds = $orders->keys()->merge($previousUnpaid->keys())->unique();
        $customers = Customer::whereKey($customerIds)->orderBy('name')->get();

        $rows = $customers->map(function (Customer $customer) use ($orders, $previousUnpaid) {
            $customerOrders = $orders->get($customer->id, collect());
            $shipped = (int) $customerOrders->sum('total_amount');
            $paid = (int) $customerOrders->filter(fn (Order $o) => $o->payment?->status === PaymentStatus::Paid)->sum('total_amount');
            $previous = (int) ($previousUnpaid[$customer->id] ?? 0);

            return [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'billing_type' => $customer->billing_type->value,
                'billing_type_label' => $customer->billing_type->label(),
                'order_count' => $customerOrders->count(),
                'shipped_total' => $shipped,
                'paid_total' => $paid,
                'unpaid_total' => $shipped - $paid,
                'previous_unpaid' => $previous,
                'outstanding' => $shipped - $paid + $previous,
            ];
        })->values();

        $totals = collect(['order_count', 'shipped_total', 'paid_total', 'unpaid_total', 'previous_unpaid', 'outstanding'])
            ->mapWithKeys(fn ($key) => [$key => (int) $rows->sum($key)])
            ->all();

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** @return Collection<int, Order> */
    public function customerOrders(Carbon $monthStart, Customer $customer): Collection
    {
        return $this->shippedOrders($monthStart, $monthStart->copy()->endOfMonth())
            ->where('customer_id', $customer->id)
            ->with('items', 'payment', 'customer')
            ->orderBy('shipped_at')
            ->get();
    }

    /** @return Collection<int, Order> 匯出明細用：當月所有出貨訂單 */
    public function allShippedOrders(Carbon $monthStart, ?BillingType $billingType = null): Collection
    {
        return $this->shippedOrders($monthStart, $monthStart->copy()->endOfMonth())
            ->when($billingType, fn ($q) => $q->whereRelation('customer', 'billing_type', $billingType))
            ->with('items', 'payment', 'customer')
            ->get()
            ->sortBy([fn ($a, $b) => strcmp($a->customer->name, $b->customer->name), ['shipped_at', 'asc']])
            ->values();
    }

    private function shippedOrders(Carbon $from, Carbon $to): Builder
    {
        return Order::query()
            ->where('status', OrderStatus::Shipped)
            ->whereBetween('shipped_at', [$from, $to]);
    }
}
