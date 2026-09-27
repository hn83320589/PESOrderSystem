<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Active = 'active';
    case Fulfilled = 'fulfilled';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => '預留中',
            self::Fulfilled => '已下單',
            self::Expired => '已到期釋放',
            self::Cancelled => '已取消',
        };
    }
}
