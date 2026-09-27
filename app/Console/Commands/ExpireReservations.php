<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = '釋放已到期、未下單的熟客預留額度';

    public function handle(ReservationService $reservations): int
    {
        $count = $reservations->expireDue();
        $this->info("已釋放 {$count} 筆到期預留");

        return self::SUCCESS;
    }
}
