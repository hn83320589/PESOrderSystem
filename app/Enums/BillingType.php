<?php

namespace App\Enums;

enum BillingType: string
{
    case Monthly = 'monthly';
    case CashOnDelivery = 'cash_on_delivery';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => '月結',
            self::CashOnDelivery => '貨到付款',
        };
    }
}
