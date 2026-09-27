<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 多型關聯在資料庫存短別名，避免日後類別改名導致舊紀錄對不上
        Relation::enforceMorphMap([
            'order' => Order::class,
            'reservation' => Reservation::class,
            'customer' => Customer::class,
            'user' => User::class,
        ]);
    }
}
