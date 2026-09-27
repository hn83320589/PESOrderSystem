<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case CashOnDelivery = 'cash_on_delivery';
    case Check = 'check';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => '匯款',
            self::CashOnDelivery => '現金貨到付款',
            self::Check => '支票',
        };
    }
}
