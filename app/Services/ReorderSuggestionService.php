<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Inventory;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderItem;

/**
 * 叫貨建議（只建議，由老闆確認後才成立進貨單）。
 *
 *   目標量   = max(低庫存門檻, 近 30 天出貨量)
 *   列入條件 = 可用量 + 在途量 < 目標量
 *   建議數量 = 目標量 − 可用量 − 在途量
 */
class ReorderSuggestionService
{
    public const LOOKBACK_DAYS = 30;

    /** @return array<int, array<string, mixed>> 依缺口由大到小 */
    public function suggestions(): array
    {
        $shipped = OrderItem::whereHas('order', fn ($q) => $q->where('status', OrderStatus::Shipped)
            ->where('shipped_at', '>=', now()->subDays(self::LOOKBACK_DAYS)))
            ->selectRaw('product_variant_id, SUM(quantity) as total')
            ->groupBy('product_variant_id')
            ->pluck('total', 'product_variant_id');

        $onOrder = PurchaseOrderItem::whereHas('purchaseOrder', fn ($q) => $q->where('status', PurchaseOrderStatus::Ordered))
            ->selectRaw('product_variant_id, SUM(quantity) as total')
            ->groupBy('product_variant_id')
            ->pluck('total', 'product_variant_id');

        $lastPurchases = PurchaseOrderItem::with('purchaseOrder.supplier')
            ->whereHas('purchaseOrder', fn ($q) => $q->where('status', '!=', PurchaseOrderStatus::Cancelled))
            ->orderByDesc('id')
            ->get()
            ->unique('product_variant_id')
            ->keyBy('product_variant_id');

        return ProductVariant::with('product', 'inventory')
            ->where('is_active', true)
            ->whereRelation('product', 'is_active', true)
            ->get()
            ->map(function (ProductVariant $variant) use ($shipped, $onOrder, $lastPurchases) {
                $available = $variant->inventory->available();
                $shipped30 = (int) ($shipped[$variant->id] ?? 0);
                $inTransit = (int) ($onOrder[$variant->id] ?? 0);
                $target = max(Inventory::LOW_STOCK_THRESHOLD, $shipped30);
                $last = $lastPurchases->get($variant->id);

                return [
                    'variant_id' => $variant->id,
                    'product_name' => $variant->product->name,
                    'spec' => $variant->spec,
                    'unit' => $variant->product->unit,
                    'available' => $available,
                    'on_order' => $inTransit,
                    'shipped_30_days' => $shipped30,
                    'target' => $target,
                    'suggested_quantity' => $target - $available - $inTransit,
                    'avg_cost' => $variant->avg_cost,
                    'last_supplier_id' => $last?->purchaseOrder->supplier_id,
                    'last_supplier_name' => $last?->purchaseOrder->supplier->name,
                    'last_unit_cost' => $last?->unit_cost,
                ];
            })
            ->filter(fn (array $s) => $s['suggested_quantity'] > 0)
            ->sortByDesc('suggested_quantity')
            ->values()
            ->all();
    }
}
