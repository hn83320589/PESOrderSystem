<?php

namespace App\Http\Resources;

use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryMovement */
class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'on_hand_change' => $this->on_hand_change,
            'reserved_change' => $this->reserved_change,
            'allocated_change' => $this->allocated_change,
            'on_hand_after' => $this->on_hand_after,
            'reserved_after' => $this->reserved_after,
            'allocated_after' => $this->allocated_after,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'user_name' => $this->user?->name,
            'note' => $this->note,
            'created_at' => $this->created_at,
        ];
    }
}
