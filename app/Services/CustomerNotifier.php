<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Customer;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Model;

/**
 * 對客戶發送通知的唯一入口。目前寫入站內通知；LINE 推播於 Step 5 加入。
 */
class CustomerNotifier
{
    public function notify(Customer $customer, string $type, string $title, string $body, ?Model $related = null): NotificationLog
    {
        return NotificationLog::create([
            'customer_id' => $customer->id,
            'channel' => NotificationChannel::Site,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
        ]);
    }
}
