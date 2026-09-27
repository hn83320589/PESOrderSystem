<?php

namespace App\Http\Resources\Customer;

use App\Http\Resources\OrderResource;
use Illuminate\Http\Request;

/**
 * 客戶看到的訂單：不含內部經手人員。
 */
class CustomerOrderResource extends OrderResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        unset($data['created_by'], $data['customer'], $data['print_count']);
        $data['items'] = CustomerOrderItemResource::collection($this->whenLoaded('items'));

        return $data;
    }
}
