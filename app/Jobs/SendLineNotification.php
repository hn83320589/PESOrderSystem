<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Exceptions\LineApiException;
use App\Models\NotificationLog;
use App\Services\Line\LineMessagingClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendLineNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> 秒 */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly int $notificationLogId)
    {
        // 等建立通知的交易提交後才發送，避免交易回滾卻已通知客戶
        $this->afterCommit();
    }

    public function handle(LineMessagingClient $line): void
    {
        $log = NotificationLog::with('customer')->find($this->notificationLogId);
        if (! $log || $log->status !== NotificationStatus::Pending) {
            return;
        }

        $lineUserId = $log->customer?->line_user_id;
        if (! $lineUserId) {
            $log->update(['status' => NotificationStatus::Skipped, 'error' => '發送時客戶已解除 LINE 綁定']);

            return;
        }

        try {
            $line->pushText($lineUserId, "{$log->title}\n\n{$log->body}", $log->retry_key);
        } catch (LineApiException $e) {
            $log->update(['error' => $e->getMessage()]);
            if (! $e->retryable) {
                $log->update(['status' => NotificationStatus::Failed]);

                return;
            }
            throw $e;
        }

        $log->update(['status' => NotificationStatus::Sent, 'sent_at' => now(), 'error' => null]);
    }

    public function failed(?Throwable $exception): void
    {
        NotificationLog::whereKey($this->notificationLogId)->update([
            'status' => NotificationStatus::Failed,
            'error' => '重試多次仍失敗：'.$exception?->getMessage(),
        ]);
    }
}
