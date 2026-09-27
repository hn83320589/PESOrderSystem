<?php

namespace App\Http\Resources;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Reservation */
class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => $this->whenLoaded('customer', fn () => ['id' => $this->customer->id, 'name' => $this->customer->name]),
            'variant' => new VariantResource($this->whenLoaded('variant')),
            'quantity' => $this->quantity,
            'expires_at' => $this->expires_at,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'reminded_at' => $this->reminded_at,
            'order_id' => $this->order_id,
            'note' => $this->note,
            'created_at' => $this->created_at,
        ];
    }
}
