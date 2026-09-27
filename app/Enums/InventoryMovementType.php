<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case Adjust = 'adjust';
    case Reserve = 'reserve';
    case ReleaseReservation = 'release_reservation';
    case Allocate = 'allocate';
    case ReleaseAllocation = 'release_allocation';
    case Ship = 'ship';

    public function label(): string
    {
        return match ($this) {
            self::Adjust => '庫存調整',
            self::Reserve => '熟客預留',
            self::ReleaseReservation => '預留釋放',
            self::Allocate => '訂單佔用',
            self::ReleaseAllocation => '訂單釋放',
            self::Ship => '出貨',
        };
    }
}
