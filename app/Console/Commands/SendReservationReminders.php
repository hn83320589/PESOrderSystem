<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

class SendReservationReminders extends Command
{
    protected $signature = 'reservations:remind';

    protected $description = '提醒客戶 7 天內即將到期的預留額度';

    public function handle(ReservationService $reservations): int
    {
        $count = $reservations->remindDue();
        $this->info("已提醒 {$count} 筆即將到期預留");

        return self::SUCCESS;
    }
}
