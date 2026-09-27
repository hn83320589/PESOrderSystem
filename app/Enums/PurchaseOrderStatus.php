<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Ordered = 'ordered';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Ordered => '已下單未到貨',
            self::Received => '已到貨入庫',
            self::Cancelled => '已取消',
        };
    }
}
