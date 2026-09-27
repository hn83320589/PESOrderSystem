<?php

use Illuminate\Support\Facades\Schedule;

// 正式環境需設定 cron：* * * * * php artisan schedule:run
Schedule::command('reservations:expire')->hourly()->withoutOverlapping();
Schedule::command('reservations:remind')->dailyAt('09:00')->withoutOverlapping();
