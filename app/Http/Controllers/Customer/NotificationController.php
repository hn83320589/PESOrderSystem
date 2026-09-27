<?php

namespace App\Http\Controllers\Customer;

use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 站內通知（與 LINE 推播並行，內容相同）。
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $this->query($request)->latest('id')->limit(50)->get()->map(fn ($n) => [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'related_type' => $n->related_type,
            'related_id' => $n->related_id,
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at,
        ]);

        return response()->json(['data' => $notifications]);
    }

    public function read(Request $request, int $notification): JsonResponse
    {
        $this->query($request)->findOrFail($notification)->update(['read_at' => now()]);

        return response()->json(['message' => 'ok']);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->query($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'ok']);
    }

    private function query(Request $request)
    {
        return $request->user('customer')->notifications()->where('channel', NotificationChannel::Site);
    }
}
