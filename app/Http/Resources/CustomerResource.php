<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
            'address' => $this->address,
            'billing_type' => $this->billing_type->value,
            'billing_type_label' => $this->billing_type->label(),
            'line_bound' => $this->line_user_id !== null,
            'line_display_name' => $this->line_display_name,
            'line_is_friend' => $this->line_is_friend,
            'note' => $this->note,
            'is_active' => $this->is_active,
        ];
    }
}
