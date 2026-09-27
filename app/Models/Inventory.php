<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 數量欄位只能透過 InventoryService 異動，以確保並發安全與異動紀錄完整。
 */
#[Table('inventory')]
#[Fillable(['on_hand', 'reserved', 'allocated'])]
class Inventory extends Model
{
    /** 可用量低於此數視為低庫存（後台總覽、客戶端「剩不多」共用） */
    public const LOW_STOCK_THRESHOLD = 10;

    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'allocated' => 'integer',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function available(): int
    {
        return $this->on_hand - $this->reserved - $this->allocated;
    }
}
