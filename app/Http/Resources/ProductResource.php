<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'unit' => $this->unit,
            'is_active' => $this->is_active,
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}
