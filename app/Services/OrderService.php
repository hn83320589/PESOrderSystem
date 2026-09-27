<?php

namespace App\Services;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 訂單生命週期：待確認 → 已確認 → 已出貨；待確認/已確認 → 已失效。
 * 內部代客下單與客戶自行下單共用此服務。
 */
class OrderService
{
    public const REORDER_LIMIT = 10;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ReservationService $reservations,
        private readonly CustomerNotifier $notifier,
        private readonly CustomerMessages $messages,
        private readonly OrderPdfService $pdf,
    ) {}

    /**
     * @param  array<int, array{variant_id: int, quantity: int}>  $items
     */
    public function create(
        Customer $customer,
        array $items,
        PaymentMethod $paymentMethod,
        OrderSource $source,
        ?User $user = null,
        ?string $note = null,
        ?string $clientRequestId = null,
    ): Order {
        // 同一客戶重複送出同一請求（連點、網路重送）時回傳既有訂單
        if ($clientRequestId && $existing = $this->findByClientRequest($customer, $clientRequestId)) {
            return $existing;
        }

        $lines = $this->normalizeLines($items);
        $variants = $this->orderableVariants(array_keys($lines));

        try {
            $order = $this->createInTransaction($customer, $lines, $variants, $paymentMethod, $source, $user, $note, $clientRequestId);
        } catch (UniqueConstraintViolationException $e) {
            // 兩個重複請求同時抵達：後到者撞到唯一索引，改回傳先成立的那張
            return $this->findByClientRequest($customer, (string) $clientRequestId) ?? throw $e;
        }

        // 已定案：下單當下即產生 PDF
        $this->pdf->generate($order);

        return $order;
    }

    private function findByClientRequest(Customer $customer, string $clientRequestId): ?Order
    {
        return $customer->orders()->where('client_request_id', $clientRequestId)->with('items', 'payment')->first();
    }

    private function createInTransaction(
        Customer $customer,
        array $lines,
        \Illuminate\Support\Collection $variants,
        PaymentMethod $paymentMethod,
        OrderSource $source,
        ?User $user,
        ?string $note,
        ?string $clientRequestId,
    ): Order {
        return DB::transaction(function () use ($customer, $lines, $variants, $paymentMethod, $source, $user, $note, $clientRequestId) {
            $order = Order::create([
                'order_no' => 'TMP-'.Str::uuid(),
                'customer_id' => $customer->id,
                'status' => OrderStatus::Pending,
                'payment_method' => $paymentMethod,
                'source' => $source,
                'client_request_id' => $clientRequestId,
                'created_by' => $user?->id,
                'note' => $note,
            ]);
            // 以流水號組單號，保證唯一且不需額外鎖定
            $order->update(['order_no' => $order->created_at->format('Ymd').'-'.str_pad((string) $order->id, 4, '0', STR_PAD_LEFT)]);

            foreach ($lines as $variantId => $quantity) {
                $fromReserved = $this->reservations->consumeForOrder($customer, $variantId, $quantity, $order, $user);
                $this->inventory->allocate($variantId, $quantity, $order, $user, $fromReserved);
                $order->items()->create($this->snapshot($variants[$variantId], $quantity));
            }

            $total = $order->items()->sum('subtotal');
            $order->update(['total_amount' => $total]);
            $order->payment()->create([
                'customer_id' => $customer->id,
                'method' => $paymentMethod,
                'amount' => $total,
                'status' => PaymentStatus::Unpaid,
            ]);

            return $order->load('items', 'payment');
        });
    }

    /**
     * 改單。$items 為 null 表示不改明細；$note 為 null 表示不改備註。
     * 庫存只異動差額；既有品項沿用下單時的單價，新增品項採目前售價。
     *
     * @param  array<int, array{variant_id: int, quantity: int}>|null  $items
     */
    public function update(Order $order, ?array $items = null, ?PaymentMethod $paymentMethod = null, ?string $note = null, ?User $user = null): Order
    {
        $lines = $items === null ? null : $this->normalizeLines($items);

        $order = DB::transaction(function () use ($order, $lines, $paymentMethod, $note, $user) {
            $order = Order::with('items', 'payment')->lockForUpdate()->findOrFail($order->id);
            $this->assertEditable($order);

            if ($lines !== null) {
                $this->syncItems($order, $lines, $user);
            }

            $total = $order->items()->sum('subtotal');
            $order->update(array_filter([
                'total_amount' => $total,
                'payment_method' => $paymentMethod,
                'note' => $note,
            ], fn ($value) => $value !== null));
            $order->payment->update(['amount' => $total, 'method' => $order->payment_method]);

            return $order->load('items', 'payment');
        });

        // 內容已變更，重新產生 PDF（補印則沿用此最新版本）
        $this->pdf->generate($order);

        return $order;
    }

    public function confirm(Order $order, ?User $user = null): Order
    {
        $order = $this->transition($order, [OrderStatus::Pending], OrderStatus::Confirmed, '確認', ['confirmed_at' => now()]);

        [$title, $body] = $this->messages->orderConfirmed($order);
        $this->notifier->notify($order->customer, 'order_confirmed', $title, $body, $order);

        return $order;
    }

    public function ship(Order $order, ?User $user = null): Order
    {
        return $this->transition($order, [OrderStatus::Confirmed], OrderStatus::Shipped, '出貨', ['shipped_at' => now()],
            function (Order $order) use ($user) {
                // 出貨當下的平均成本快照，之後進價變動不影響已出貨的毛利
                $costs = ProductVariant::whereIn('id', $order->items->pluck('product_variant_id'))->pluck('avg_cost', 'id');
                foreach ($order->items as $item) {
                    $item->update(['unit_cost' => $costs[$item->product_variant_id] ?? null]);
                    $this->inventory->ship($item->product_variant_id, $item->quantity, $order, $user);
                }
            });
    }

    public function expire(Order $order, ?User $user = null): Order
    {
        if ($order->payment?->status === PaymentStatus::Paid) {
            throw ValidationException::withMessages(['status' => '此訂單已收款，請先處理退款再設為失效']);
        }

        return $this->transition($order, [OrderStatus::Pending, OrderStatus::Confirmed], OrderStatus::Expired, '設為失效', ['expired_at' => now()],
            function (Order $order) use ($user) {
                foreach ($order->items as $item) {
                    $this->inventory->releaseAllocation($item->product_variant_id, $item->quantity, $order, $user);
                }
            });
    }

    /**
     * 「老樣子」：該客戶最近的有效訂單，最新的在前。
     *
     * @return Collection<int, Order>
     */
    public function recentForReorder(Customer $customer, int $limit = self::REORDER_LIMIT): Collection
    {
        return $customer->orders()
            ->where('status', '!=', OrderStatus::Expired)
            ->with('items.variant.product', 'items.variant.inventory')
            ->latest()->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<int, int>  $lines  variant_id => quantity
     */
    private function syncItems(Order $order, array $lines, ?User $user): void
    {
        $existing = $order->items->keyBy('product_variant_id');
        $added = array_diff(array_keys($lines), $existing->keys()->all());
        $variants = $this->orderableVariants($added);

        // 先釋放再佔用，讓減少的數量可立即用於同張單的其他品項
        foreach ($existing as $variantId => $item) {
            $diff = ($lines[$variantId] ?? 0) - $item->quantity;
            if ($diff < 0) {
                $this->inventory->releaseAllocation($variantId, -$diff, $order, $user);
            }
        }

        foreach ($lines as $variantId => $quantity) {
            $item = $existing->get($variantId);
            $diff = $quantity - ($item?->quantity ?? 0);
            if ($diff > 0) {
                $this->inventory->allocate($variantId, $diff, $order, $user);
            }

            if ($item === null) {
                $order->items()->create($this->snapshot($variants[$variantId], $quantity));
            } elseif ($diff !== 0) {
                $item->update(['quantity' => $quantity, 'subtotal' => $item->unit_price * $quantity]);
            }
        }

        foreach ($existing as $variantId => $item) {
            if (! isset($lines[$variantId])) {
                $item->delete();
            }
        }
        $order->unsetRelation('items');
    }

    /**
     * @param  array<int, OrderStatus>  $from
     */
    private function transition(Order $order, array $from, OrderStatus $to, string $action, array $timestamps, ?callable $effects = null): Order
    {
        return DB::transaction(function () use ($order, $from, $to, $action, $timestamps, $effects) {
            // 條件式更新搶下狀態轉換，兩人同時操作時只有一方成功
            $claimed = Order::whereKey($order->id)
                ->whereIn('status', $from)
                ->update(['status' => $to, ...$timestamps, 'updated_at' => now()]);
            if ($claimed === 0) {
                $current = Order::findOrFail($order->id)->status;
                throw ValidationException::withMessages(['status' => "訂單目前為「{$current->label()}」，無法{$action}"]);
            }

            $order = Order::with('items', 'payment')->findOrFail($order->id);
            if ($effects) {
                $effects($order);
            }

            return $order;
        });
    }

    private function assertEditable(Order $order): void
    {
        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Confirmed], true)) {
            throw ValidationException::withMessages(['status' => "訂單目前為「{$order->status->label()}」，不可修改"]);
        }
        if ($order->payment?->status === PaymentStatus::Paid) {
            throw ValidationException::withMessages(['status' => '此訂單已收款，不可修改']);
        }
    }

    /**
     * 合併重複品項並檢查數量。
     *
     * @return array<int, int> variant_id => quantity
     */
    private function normalizeLines(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $variantId = (int) ($item['variant_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($variantId <= 0 || $quantity <= 0) {
                throw ValidationException::withMessages(['items' => '品項與數量必須正確填寫，數量需大於 0']);
            }
            $lines[$variantId] = ($lines[$variantId] ?? 0) + $quantity;
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => '訂單至少需要一個品項']);
        }

        return $lines;
    }

    /**
     * @param  array<int, int>  $variantIds
     * @return \Illuminate\Support\Collection<int, ProductVariant>
     */
    private function orderableVariants(array $variantIds): \Illuminate\Support\Collection
    {
        $variants = ProductVariant::with('product')->whereIn('id', $variantIds)->get()->keyBy('id');

        foreach ($variantIds as $variantId) {
            $variant = $variants->get($variantId);
            if (! $variant || ! $variant->is_active || ! $variant->product->is_active) {
                $name = $variant ? "「{$variant->product->name} {$variant->spec}」" : "規格 #{$variantId}";
                throw ValidationException::withMessages(['items' => "{$name}已停售或不存在"]);
            }
        }

        return $variants;
    }

    private function snapshot(ProductVariant $variant, int $quantity): array
    {
        return [
            'product_variant_id' => $variant->id,
            'product_name' => $variant->product->name,
            'spec' => $variant->spec,
            'unit' => $variant->product->unit,
            'unit_price' => $variant->price,
            'quantity' => $quantity,
            'subtotal' => $variant->price * $quantity,
        ];
    }
}
