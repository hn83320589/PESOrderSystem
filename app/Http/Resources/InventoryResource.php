<?php

namespace App\Http\Resources;

use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Inventory */
class InventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'variant_id' => $this->product_variant_id,
            'on_hand' => $this->on_hand,
            'reserved' => $this->reserved,
            'allocated' => $this->allocated,
            'available' => $this->available(),
        ];
    }
}
