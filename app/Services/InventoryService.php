<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 所有庫存數量異動的唯一入口。
 *
 * 可用量 = on_hand - reserved - allocated。每次異動都是一個條件式原子 UPDATE：
 * 條件不成立（任一欄會變負數、或可用量會變負數）時影響 0 列並丟出例外，
 * 不依賴 SELECT ... FOR UPDATE，因此在 SQLite（開發）與 MySQL（正式）行為一致。
 */
class InventoryService
{
    /** 進貨（正數）或盤點調整（負數） */
    public function adjust(int $variantId, int $delta, ?User $user = null, ?string $note = null): InventoryMovement
    {
        if ($delta === 0) {
            throw new InvalidArgumentException('調整數量不可為 0');
        }

        return $this->apply($variantId, InventoryMovementType::Adjust, $delta, 0, 0, null, $user, $note);
    }

    /** 進貨單到貨入庫 */
    public function receive(int $variantId, int $quantity, Model $reference, ?User $user = null): InventoryMovement
    {
        $this->assertPositive($quantity);

        return $this->apply($variantId, InventoryMovementType::Purchase, $quantity, 0, 0, $reference, $user);
    }

    public function reserve(int $variantId, int $quantity, Model $reference, ?User $user = null): InventoryMovement
    {
        $this->assertPositive($quantity);

        return $this->apply($variantId, InventoryMovementType::Reserve, 0, $quantity, 0, $reference, $user);
    }

    public function releaseReservation(int $variantId, int $quantity, Model $reference, ?User $user = null): InventoryMovement
    {
        $this->assertPositive($quantity);

        return $this->apply($variantId, InventoryMovementType::ReleaseReservation, 0, -$quantity, 0, $reference, $user);
    }

    /**
     * 下單佔用庫存。$fromReserved 為其中由該客戶預留額度轉入的數量。
     */
    public function allocate(int $variantId, int $quantity, Model $reference, ?User $user = null, int $fromReserved = 0): InventoryMovement
    {
        $this->assertPositive($quantity);
        if ($fromReserved < 0 || $fromReserved > $quantity) {
            throw new InvalidArgumentException('fromReserved 必須介於 0 與訂購數量之間');
        }

        return $this->apply($variantId, InventoryMovementType::Allocate, 0, -$fromReserved, $quantity, $reference, $user);
    }

    public function releaseAllocation(int $variantId, int $quantity, Model $reference, ?User $user = null): InventoryMovement
    {
        $this->assertPositive($quantity);

        return $this->apply($variantId, InventoryMovementType::ReleaseAllocation, 0, 0, -$quantity, $reference, $user);
    }

    public function ship(int $variantId, int $quantity, Model $reference, ?User $user = null): InventoryMovement
    {
        $this->assertPositive($quantity);

        return $this->apply($variantId, InventoryMovementType::Ship, -$quantity, 0, -$quantity, $reference, $user);
    }

    private function apply(
        int $variantId,
        InventoryMovementType $type,
        int $onHandChange,
        int $reservedChange,
        int $allocatedChange,
        ?Model $reference,
        ?User $user,
        ?string $note = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($variantId, $type, $onHandChange, $reservedChange, $allocatedChange, $reference, $user, $note) {
            $changes = ['on_hand' => $onHandChange, 'reserved' => $reservedChange, 'allocated' => $allocatedChange];

            $query = Inventory::where('product_variant_id', $variantId);
            $updates = ['updated_at' => now()];
            foreach ($changes as $column => $change) {
                if ($change > 0) {
                    $updates[$column] = DB::raw("{$column} + {$change}");
                } elseif ($change < 0) {
                    $query->where($column, '>=', -$change);
                    $updates[$column] = DB::raw("{$column} - ".(-$change));
                }
            }

            // 可用量不可為負：(on_hand + Δo) - (reserved + Δr) - (allocated + Δa) >= 0
            // 移項成兩邊都只有加法，避免 MySQL unsigned 欄位出現負數中間值而報錯
            $gain = max($onHandChange, 0) + max(-$reservedChange, 0) + max(-$allocatedChange, 0);
            $loss = max(-$onHandChange, 0) + max($reservedChange, 0) + max($allocatedChange, 0);
            $query->whereRaw('on_hand + ? >= reserved + allocated + ?', [$gain, $loss]);

            if ($query->update($updates) === 0) {
                $this->throwInsufficient($variantId, $loss - $gain);
            }

            $after = Inventory::where('product_variant_id', $variantId)->firstOrFail();

            return InventoryMovement::create([
                'product_variant_id' => $variantId,
                'type' => $type,
                'on_hand_change' => $onHandChange,
                'reserved_change' => $reservedChange,
                'allocated_change' => $allocatedChange,
                'on_hand_after' => $after->on_hand,
                'reserved_after' => $after->reserved,
                'allocated_after' => $after->allocated,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'user_id' => $user?->id,
                'note' => $note,
            ]);
        });
    }

    private function throwInsufficient(int $variantId, int $requested): never
    {
        $inventory = Inventory::with('variant.product')->where('product_variant_id', $variantId)->first()
            ?? throw new InvalidArgumentException("規格 #{$variantId} 不存在");
        $variant = $inventory->variant;

        throw new InsufficientStockException(
            $variantId,
            $requested,
            $inventory->available(),
            "「{$variant->product->name} {$variant->spec}」庫存不足：需要 {$requested}，目前可用 {$inventory->available()}",
        );
    }

    private function assertPositive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('數量必須大於 0');
        }
    }
}
