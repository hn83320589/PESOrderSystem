<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->id,
                'order_no' => $this->order->order_no,
                'status' => $this->order->status->value,
                'status_label' => $this->order->status->label(),
                'created_at' => $this->order->created_at,
                'shipped_at' => $this->order->shipped_at,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => ['id' => $this->customer->id, 'name' => $this->customer->name]),
            'amount' => $this->amount,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'paid_at' => $this->paid_at,
            'check_no' => $this->check_no,
            'check_due_date' => $this->check_due_date?->toDateString(),
            'note' => $this->note,
        ];
    }
}
