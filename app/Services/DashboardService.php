<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Line\LineMessagingClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * 老闆總覽：應收、出貨與收款趨勢、帳齡、熱銷商品。
 *
 * 月份分組在 PHP 內以台北時間進行，不用資料庫日期函式：SQLite 與 MySQL 寫法不同，
 * 此規模（每年數千張單）全部在 PHP 彙總即可，並保證開發與正式環境結果一致。
 */
class DashboardService
{
    private const TOP_LIMIT = 10;

    /** 帳齡區間：[標籤, 天數上限（含）] */
    private const AGING_BUCKETS = [['0–30 天', 30], ['31–60 天', 60], ['61–90 天', 90], ['90 天以上', PHP_INT_MAX]];

    public function __construct(private readonly LineMessagingClient $line) {}

    public function build(int $months): array
    {
        $receivables = $this->unpaidShipped();

        return [
            'summary' => $this->summary($receivables),
            'trend' => $this->trend($months),
            'aging' => $this->aging($receivables),
            'top_receivables' => $this->topReceivables($receivables),
            'top_products' => $this->topProducts($months),
            // 快取 10 分鐘，避免每次開總覽都呼叫 LINE API
            'line_quota' => Cache::remember('dashboard:line-quota', 600, fn () => $this->line->quota()),
        ];
    }

    private function summary(Collection $receivables): array
    {
        $thisMonth = now()->startOfMonth();
        $lastMonth = $thisMonth->copy()->subMonthNoOverflow();

        return [
            ...$this->grossProfit($thisMonth),
            'receivable' => (int) $receivables->sum('amount'),
            'shipped_this_month' => $this->shippedTotal($thisMonth, now()),
            'shipped_last_month' => $this->shippedTotal($lastMonth, $thisMonth->copy()->subSecond()),
            'collected_this_month' => (int) $this->paidSince($thisMonth)->sum('amount'),
            'pending_orders' => Order::where('status', OrderStatus::Pending)->count(),
            'low_stock_variants' => Inventory::whereRelation('variant', 'is_active', true)
                ->whereRaw('on_hand < reserved + allocated + ?', [Inventory::LOW_STOCK_THRESHOLD])
                ->count(),
        ];
    }

    /** 最近 N 個月（含本月）每月出貨與收款金額，沒有資料的月份補 0 */
    private function trend(int $months): array
    {
        $start = now()->startOfMonth()->subMonthsNoOverflow($months - 1);

        $shipped = Order::where('status', OrderStatus::Shipped)->where('shipped_at', '>=', $start)->get(['total_amount', 'shipped_at'])
            ->groupBy(fn (Order $o) => $o->shipped_at->format('Y-m'))
            ->map(fn (Collection $group) => (int) $group->sum('total_amount'));
        $collected = $this->paidSince($start)
            ->groupBy(fn (Payment $p) => $p->paid_at->format('Y-m'))
            ->map(fn (Collection $group) => (int) $group->sum('amount'));

        return collect(range(0, $months - 1))->map(function (int $offset) use ($start, $shipped, $collected) {
            $month = $start->copy()->addMonthsNoOverflow($offset)->format('Y-m');

            return ['month' => $month, 'shipped' => $shipped[$month] ?? 0, 'collected' => $collected[$month] ?? 0];
        })->all();
    }

    /** 未收款依出貨至今天數分組 */
    private function aging(Collection $receivables): array
    {
        return collect(self::AGING_BUCKETS)->map(function (array $bucket, int $index) use ($receivables) {
            $lower = $index === 0 ? -1 : self::AGING_BUCKETS[$index - 1][1];
            $inBucket = $receivables->filter(fn (array $r) => $r['days'] > $lower && $r['days'] <= $bucket[1]);

            return ['label' => $bucket[0], 'amount' => (int) $inBucket->sum('amount'), 'orders' => $inBucket->count()];
        })->all();
    }

    private function topReceivables(Collection $receivables): array
    {
        return $receivables->groupBy('customer_id')
            ->map(fn (Collection $group) => [
                'customer_id' => $group->first()['customer_id'],
                'customer_name' => $group->first()['customer_name'],
                'billing_type_label' => $group->first()['billing_type_label'],
                'amount' => (int) $group->sum('amount'),
                'orders' => $group->count(),
                'oldest_days' => $group->max('days'),
            ])
            ->sortByDesc('amount')
            ->take(self::TOP_LIMIT)
            ->values()
            ->all();
    }

    /** 期間內已出貨商品，依品名（下單快照）加總金額 */
    private function topProducts(int $months): array
    {
        $start = now()->startOfMonth()->subMonthsNoOverflow($months - 1);

        return OrderItem::whereHas('order', fn ($q) => $q->where('status', OrderStatus::Shipped)->where('shipped_at', '>=', $start))
            ->get(['product_name', 'unit', 'quantity', 'subtotal'])
            ->groupBy('product_name')
            ->map(fn (Collection $group, string $name) => [
                'product_name' => $name,
                'unit' => $group->first()->unit,
                'quantity' => (int) $group->sum('quantity'),
                'amount' => (int) $group->sum('subtotal'),
            ])
            ->sortByDesc('amount')
            ->take(self::TOP_LIMIT)
            ->values()
            ->all();
    }

    /**
     * 已出貨、尚未收款的訂單（應收帳款）。
     *
     * @return Collection<int, array{customer_id: int, customer_name: string, billing_type_label: string, amount: int, days: int}>
     */
    private function unpaidShipped(): Collection
    {
        $today = now()->startOfDay();

        return Payment::with('order', 'customer')
            ->where('status', PaymentStatus::Unpaid)
            ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Shipped))
            ->get()
            ->map(fn (Payment $p) => [
                'customer_id' => $p->customer_id,
                'customer_name' => $p->customer->name,
                'billing_type_label' => $p->customer->billing_type->label(),
                'amount' => $p->amount,
                'days' => (int) $p->order->shipped_at->copy()->startOfDay()->diffInDays($today),
            ]);
    }

    /**
     * 本月至今毛利。只計有成本快照的品項（第一階段的舊單、尚未進過貨的商品沒有成本），
     * 並回傳涵蓋率，避免把沒有成本的銷售當成 100% 毛利。
     */
    private function grossProfit(Carbon $from): array
    {
        $items = OrderItem::whereHas('order', fn ($q) => $q->where('status', OrderStatus::Shipped)->where('shipped_at', '>=', $from))
            ->get(['quantity', 'subtotal', 'unit_cost']);
        $costed = $items->whereNotNull('unit_cost');
        $costedSales = (int) $costed->sum('subtotal');
        $profit = (int) round($costed->sum(fn (OrderItem $i) => $i->subtotal - $i->unit_cost * $i->quantity));
        $totalSales = (int) $items->sum('subtotal');

        return [
            'gross_profit_this_month' => $profit,
            'gross_margin_rate' => $costedSales > 0 ? round($profit / $costedSales, 4) : null,
            'cost_coverage' => $totalSales > 0 ? round($costedSales / $totalSales, 4) : null,
        ];
    }

    private function shippedTotal(Carbon $from, Carbon $to): int
    {
        return (int) Order::where('status', OrderStatus::Shipped)->whereBetween('shipped_at', [$from, $to])->sum('total_amount');
    }

    /** @return Collection<int, Payment> 期間內收到的款項（不含已失效訂單） */
    private function paidSince(Carbon $from): Collection
    {
        return Payment::where('status', PaymentStatus::Paid)
            ->where('paid_at', '>=', $from)
            ->whereHas('order', fn ($q) => $q->where('status', '!=', OrderStatus::Expired))
            ->get(['amount', 'paid_at']);
    }
}
