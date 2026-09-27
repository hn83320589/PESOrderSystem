<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 進貨：下單 → 到貨入庫（或當場到貨直接入庫）。
 * 入庫時以移動加權平均更新規格成本：（現有庫存 × 舊均價 + 進貨量 × 進價）÷ 合計數量。
 */
class PurchaseService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @param  array<int, array{variant_id: int, quantity: int, unit_cost: float|int|string}>  $items
     */
    public function create(Supplier $supplier, array $items, ?User $user = null, bool $receiveNow = false, ?string $note = null): PurchaseOrder
    {
        $lines = $this->normalizeLines($items);
        $variants = ProductVariant::with('product')->whereIn('id', array_keys($lines))->get()->keyBy('id');
        if ($variants->count() !== count($lines)) {
            throw ValidationException::withMessages(['items' => '有品項不存在']);
        }

        return DB::transaction(function () use ($supplier, $lines, $variants, $user, $receiveNow, $note) {
            $po = PurchaseOrder::create([
                'po_no' => 'TMP-'.Str::uuid(),
                'supplier_id' => $supplier->id,
                'status' => PurchaseOrderStatus::Ordered,
                'note' => $note,
                'created_by' => $user?->id,
            ]);
            $po->update(['po_no' => 'P'.$po->created_at->format('Ymd').'-'.str_pad((string) $po->id, 4, '0', STR_PAD_LEFT)]);

            foreach ($lines as $variantId => ['quantity' => $quantity, 'unit_cost' => $unitCost]) {
                $variant = $variants[$variantId];
                $po->items()->create([
                    'product_variant_id' => $variantId,
                    'product_name' => $variant->product->name,
                    'spec' => $variant->spec,
                    'unit' => $variant->product->unit,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'subtotal' => round($unitCost * $quantity, 2),
                ]);
            }
            $po->update(['total_cost' => $po->items()->sum('subtotal')]);

            return $receiveNow ? $this->receive($po, $user) : $po->load('items', 'supplier');
        });
    }

    public function receive(PurchaseOrder $po, ?User $user = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $user) {
            $claimed = PurchaseOrder::whereKey($po->id)
                ->where('status', PurchaseOrderStatus::Ordered)
                ->update(['status' => PurchaseOrderStatus::Received, 'received_at' => now(), 'received_by' => $user?->id, 'updated_at' => now()]);
            if ($claimed === 0) {
                throw ValidationException::withMessages(['status' => '只有「已下單未到貨」的進貨單可以入庫']);
            }

            $po = PurchaseOrder::with('items')->findOrFail($po->id);
            foreach ($po->items as $item) {
                // 鎖住規格列，避免兩張進貨單同時入庫時均價計算互相覆蓋
                $variant = ProductVariant::with('inventory')->lockForUpdate()->findOrFail($item->product_variant_id);
                $onHandBefore = $variant->inventory->on_hand;

                $this->inventory->receive($variant->id, $item->quantity, $po, $user);

                // 尚無成本資料的既有庫存，以第一次進價視為其成本
                $avgCost = $variant->avg_cost === null
                    ? $item->unit_cost
                    : ($onHandBefore * $variant->avg_cost + $item->quantity * $item->unit_cost) / ($onHandBefore + $item->quantity);
                $variant->update(['avg_cost' => round($avgCost, 2)]);
            }

            return $po->load('items', 'supplier');
        });
    }

    public function cancel(PurchaseOrder $po, ?User $user = null): PurchaseOrder
    {
        $claimed = PurchaseOrder::whereKey($po->id)
            ->where('status', PurchaseOrderStatus::Ordered)
            ->update(['status' => PurchaseOrderStatus::Cancelled, 'cancelled_at' => now(), 'updated_at' => now()]);
        if ($claimed === 0) {
            throw ValidationException::withMessages(['status' => '只有「已下單未到貨」的進貨單可以取消']);
        }

        return $po->fresh()->load('items', 'supplier');
    }

    /**
     * 合併同規格的多列，進價以數量加權。
     *
     * @return array<int, array{quantity: int, unit_cost: float}>
     */
    private function normalizeLines(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $variantId = (int) ($item['variant_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            $unitCost = (float) ($item['unit_cost'] ?? -1);
            if ($variantId <= 0 || $quantity <= 0 || $unitCost < 0) {
                throw ValidationException::withMessages(['items' => '品項、數量（大於 0）、進價（不可為負）都必須正確填寫']);
            }

            $existing = $lines[$variantId] ?? ['quantity' => 0, 'unit_cost' => 0.0];
            $total = $existing['quantity'] + $quantity;
            $lines[$variantId] = [
                'quantity' => $total,
                'unit_cost' => round(($existing['quantity'] * $existing['unit_cost'] + $quantity * $unitCost) / $total, 2),
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => '進貨單至少需要一個品項']);
        }

        return $lines;
    }
}
