<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Shipped = 'shipped';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '待確認',
            self::Confirmed => '已確認',
            self::Shipped => '已出貨',
            self::Expired => '已失效',
        };
    }
}
