<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationLogResource;
use App\Models\NotificationLog;
use App\Services\CustomerNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'channel' => ['nullable', Rule::enum(NotificationChannel::class)],
            'status' => ['nullable', Rule::enum(NotificationStatus::class)],
            'customer_id' => ['nullable', 'integer'],
        ]);

        $logs = NotificationLog::with('customer')
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->latest('id')
            ->paginate(50);

        return NotificationLogResource::collection($logs);
    }

    public function resend(NotificationLog $notification, CustomerNotifier $notifier): NotificationLogResource
    {
        return new NotificationLogResource($notifier->resend($notification)->load('customer'));
    }
}
