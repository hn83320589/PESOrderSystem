<?php

namespace App\Http\Resources;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrder */
class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_no' => $this->po_no,
            'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'name' => $this->supplier->name]),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'total_cost' => $this->total_cost,
            'note' => $this->note,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name,
                'spec' => $item->spec,
                'unit' => $item->unit,
                'quantity' => $item->quantity,
                'unit_cost' => $item->unit_cost,
                'subtotal' => $item->subtotal,
            ])),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'received_by' => $this->whenLoaded('receiver', fn () => $this->receiver?->name),
            'created_at' => $this->created_at,
            'received_at' => $this->received_at,
            'cancelled_at' => $this->cancelled_at,
        ];
    }
}
