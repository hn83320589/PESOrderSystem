<?php

namespace App\Http\Controllers\Customer;

use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $customer = $request->user('customer');

        return response()->json(['data' => [
            'name' => $customer->name,
            'contact_name' => $customer->contact_name,
            'billing_type' => $customer->billing_type->value,
            'billing_type_label' => $customer->billing_type->label(),
            'unread_notifications' => $customer->notifications()
                ->where('channel', NotificationChannel::Site)->whereNull('read_at')->count(),
        ]]);
    }
}
