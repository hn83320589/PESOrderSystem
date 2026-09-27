<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderItem */
class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variant_id' => $this->product_variant_id,
            'product_name' => $this->product_name,
            'spec' => $this->spec,
            'unit' => $this->unit,
            'unit_price' => $this->unit_price,
            'quantity' => $this->quantity,
            'subtotal' => $this->subtotal,
            // 「老樣子」帶入時需要知道目前售價與是否還能訂
            'current' => $this->whenLoaded('variant', fn () => [
                'price' => $this->variant->price,
                'available' => $this->variant->inventory?->available(),
                'orderable' => $this->variant->is_active && $this->variant->product->is_active,
            ]),
        ];
    }
}
