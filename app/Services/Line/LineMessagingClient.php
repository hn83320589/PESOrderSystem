<?php

namespace App\Services\Line;

use App\Exceptions\LineApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * LINE Messaging API 的最小封裝（推播＋webhook 驗簽）。
 * 直接使用 Laravel Http client 而非 line-bot-sdk，測試可用 Http::fake 模擬。
 */
class LineMessagingClient
{
    private const PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    private const MAX_TEXT_LENGTH = 5000;

    public function isConfigured(): bool
    {
        return filled(config('services.line.channel_access_token')) && filled(config('services.line.channel_secret'));
    }

    /**
     * @param  string  $retryKey  UUID；同一則訊息重試時必須相同，LINE 以 409 回覆已受理過的重複請求
     */
    public function pushText(string $to, string $text, string $retryKey): void
    {
        try {
            $response = Http::withToken(config('services.line.channel_access_token'))
                ->withHeaders(['X-Line-Retry-Key' => $retryKey])
                ->timeout(10)
                ->post(self::PUSH_URL, [
                    'to' => $to,
                    'messages' => [['type' => 'text', 'text' => mb_substr($text, 0, self::MAX_TEXT_LENGTH)]],
                ]);
        } catch (ConnectionException $e) {
            throw new LineApiException('無法連線至 LINE：'.$e->getMessage(), retryable: true);
        }

        if ($response->successful() || $response->status() === 409) {
            return;
        }

        throw new LineApiException(
            sprintf('LINE 推播失敗（HTTP %d）：%s', $response->status(), $response->json('message') ?? $response->body()),
            retryable: $response->status() === 429 || $response->serverError(),
        );
    }

    /** 以原始 request body 驗證 X-Line-Signature（不可先 json_decode 再編碼） */
    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        $secret = config('services.line.channel_secret');
        if (blank($secret) || blank($signature)) {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), $signature);
    }
}
