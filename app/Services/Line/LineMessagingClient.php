<?php

namespace App\Services\Line;

use App\Exceptions\LineApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LINE Messaging API 的最小封裝（推播＋webhook 驗簽）。
 * 直接使用 Laravel Http client 而非 line-bot-sdk，測試可用 Http::fake 模擬。
 */
class LineMessagingClient
{
    private const API_BASE = 'https://api.line.me';

    private const PUSH_URL = self::API_BASE.'/v2/bot/message/push';

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

        // 429 同時用於「請求太頻繁」（可重試）與「本月免費額度用完」（重試無用），只能以訊息內容區分
        if ($response->status() === 429 && str_contains(strtolower((string) $response->json('message')), 'monthly limit')) {
            throw new LineApiException(
                '本月 LINE 訊息額度已用完，當月無法再推播；請至 LINE 官方帳號管理後台升級方案（LINE 回應：'.$response->json('message').'）',
                retryable: false,
            );
        }

        throw new LineApiException(
            sprintf('LINE 推播失敗（HTTP %d）：%s', $response->status(), $response->json('message') ?? $response->body()),
            retryable: $response->status() === 429 || $response->serverError(),
        );
    }

    /**
     * 本月訊息用量與上限（含從官方帳號管理後台手動發送的訊息）。
     * 無法取得時回傳 null 並記錄，不影響呼叫端。
     *
     * @return array{used: int, limit: ?int}|null limit 為 null 表示無上限（可加購的方案）
     */
    public function quota(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $client = Http::withToken(config('services.line.channel_access_token'))->timeout(5);
            $limit = $client->get(self::API_BASE.'/v2/bot/message/quota');
            $usage = $client->get(self::API_BASE.'/v2/bot/message/quota/consumption');
        } catch (ConnectionException $e) {
            Log::warning('無法取得 LINE 訊息用量', ['error' => $e->getMessage()]);

            return null;
        }

        if ($limit->failed() || $usage->failed()) {
            Log::warning('無法取得 LINE 訊息用量', ['status' => [$limit->status(), $usage->status()]]);

            return null;
        }

        return [
            'used' => (int) $usage->json('totalUsage'),
            'limit' => $limit->json('type') === 'limited' ? (int) $limit->json('value') : null,
        ];
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
