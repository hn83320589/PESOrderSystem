<?php

namespace App\Http\Resources\Customer;

use App\Http\Resources\OrderItemResource;
use Illuminate\Http\Request;

/**
 * 客戶看到的訂單品項：不揭露實際庫存數量，只給「是否有貨」。
 */
class CustomerOrderItemResource extends OrderItemResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'current' => $this->whenLoaded('variant', fn () => [
                'price' => $this->variant->price,
                'orderable' => $this->variant->is_active && $this->variant->product->is_active,
                'in_stock' => ($this->variant->inventory?->available() ?? 0) > 0,
            ]),
        ];
    }
}
