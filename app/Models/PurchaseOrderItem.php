<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['purchase_order_id', 'product_variant_id', 'product_name', 'spec', 'unit', 'quantity', 'unit_cost', 'subtotal'])]
class PurchaseOrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'float',
            'subtotal' => 'float',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
