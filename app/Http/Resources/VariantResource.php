<?php

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class VariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product->name),
            'unit' => $this->whenLoaded('product', fn () => $this->product->unit),
            'spec' => $this->spec,
            'sku' => $this->sku,
            'price' => $this->price,
            // 僅管理端使用此 Resource；客戶端商品目錄另行組裝，不含成本
            'avg_cost' => $this->avg_cost,
            'is_active' => $this->is_active,
            'stock' => new InventoryResource($this->whenLoaded('inventory')),
        ];
    }
}
