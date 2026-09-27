<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Jobs\SendLineNotification;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Services\Line\LineMessagingClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 對客戶發送通知的唯一入口：站內通知一定寫入，LINE 推播並行（已定案需求）。
 * LINE 無法發送時（未綁定、未設定金鑰）記錄為略過並附原因，不影響呼叫端流程。
 */
class CustomerNotifier
{
    public function __construct(private readonly LineMessagingClient $line) {}

    public function notify(Customer $customer, string $type, string $title, string $body, ?Model $related = null): NotificationLog
    {
        $common = [
            'customer_id' => $customer->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
        ];

        $site = NotificationLog::create($common + [
            'channel' => NotificationChannel::Site,
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
        ]);

        $skipReason = $this->lineSkipReason($customer);

        $line = NotificationLog::create($common + [
            'channel' => NotificationChannel::Line,
            'status' => $skipReason ? NotificationStatus::Skipped : NotificationStatus::Pending,
            'error' => $skipReason,
            'retry_key' => $skipReason ? null : (string) Str::uuid(),
        ]);

        if (! $skipReason) {
            SendLineNotification::dispatch($line->id);
        }

        return $site;
    }

    /**
     * 重送失敗或略過的 LINE 通知（例如補設金鑰、客戶完成綁定後）。
     * 沿用原本的 retry key：若先前其實已送達，LINE 會以 409 擋下重複訊息。
     */
    public function resend(NotificationLog $log): NotificationLog
    {
        if ($log->channel !== NotificationChannel::Line || ! in_array($log->status, [NotificationStatus::Failed, NotificationStatus::Skipped], true)) {
            throw ValidationException::withMessages(['status' => '只有發送失敗或略過的 LINE 通知可以重送']);
        }
        if ($reason = $this->lineSkipReason($log->customer)) {
            throw ValidationException::withMessages(['status' => "無法重送：{$reason}"]);
        }

        $log->update([
            'status' => NotificationStatus::Pending,
            'error' => null,
            'retry_key' => $log->retry_key ?? (string) Str::uuid(),
        ]);
        SendLineNotification::dispatch($log->id);

        return $log->refresh();
    }

    private function lineSkipReason(Customer $customer): ?string
    {
        return match (true) {
            ! $customer->line_user_id => '客戶尚未綁定 LINE',
            ! $this->line->isConfigured() => '系統尚未設定 LINE 金鑰',
            default => null,
        };
    }
}
