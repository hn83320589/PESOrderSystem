<?php

namespace App\Enums;

enum NotificationStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    // 客戶未綁定 LINE 或系統尚未設定金鑰時，不發送但留紀錄
    case Skipped = 'skipped';
}
