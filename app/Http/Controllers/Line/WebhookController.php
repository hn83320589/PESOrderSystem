<?php

namespace App\Http\Controllers\Line;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Line\LineMessagingClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * LINE 平台 webhook。僅處理加好友／封鎖事件以掌握能否推播，不做對話機器人。
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, LineMessagingClient $line): JsonResponse
    {
        if (! $line->verifySignature($request->getContent(), $request->header('X-Line-Signature'))) {
            Log::warning('LINE webhook 簽章驗證失敗', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature'], 400);
        }

        foreach ($request->input('events', []) as $event) {
            $userId = data_get($event, 'source.userId');
            $isFriend = match (data_get($event, 'type')) {
                'follow' => true,
                'unfollow' => false,
                default => null,
            };

            if ($userId && $isFriend !== null) {
                Customer::where('line_user_id', $userId)->update(['line_is_friend' => $isFriend]);
            }
        }

        return response()->json(['message' => 'ok']);
    }
}
