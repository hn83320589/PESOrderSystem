<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'customer' => $this->whenLoaded('customer', fn () => ['id' => $this->customer->id, 'name' => $this->customer->name]),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_method' => $this->payment_method->value,
            'payment_method_label' => $this->payment_method->label(),
            'total_amount' => $this->total_amount,
            'source' => $this->source->value,
            'note' => $this->note,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payment' => $this->whenLoaded('payment', fn () => $this->payment ? [
                'amount' => $this->payment->amount,
                'status' => $this->payment->status->value,
                'status_label' => $this->payment->status->label(),
                'paid_at' => $this->payment->paid_at,
            ] : null),
            'created_at' => $this->created_at,
            'confirmed_at' => $this->confirmed_at,
            'shipped_at' => $this->shipped_at,
            'expired_at' => $this->expired_at,
        ];
    }
}
