<?php

use App\Http\Controllers\Admin;
use Illuminate\Support\Facades\Route;

/*
 * 內部管理端 API（guard: web）
 */
Route::prefix('admin')->group(function () {
    Route::post('login', [Admin\AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:web')->group(function () {
        Route::post('logout', [Admin\AuthController::class, 'logout']);
        Route::get('me', [Admin\AuthController::class, 'me']);

        Route::get('products', [Admin\ProductController::class, 'index']);
        Route::post('products', [Admin\ProductController::class, 'store']);
        Route::patch('products/{product}', [Admin\ProductController::class, 'update']);
        Route::post('products/{product}/variants', [Admin\ProductController::class, 'storeVariant']);
        Route::patch('variants/{variant}', [Admin\ProductController::class, 'updateVariant']);

        Route::get('inventory', [Admin\InventoryController::class, 'index']);
        Route::post('inventory/{variant}/adjust', [Admin\InventoryController::class, 'adjust']);
        Route::get('inventory/{variant}/movements', [Admin\InventoryController::class, 'movements']);

        Route::get('reservations', [Admin\ReservationController::class, 'index']);
        Route::post('reservations', [Admin\ReservationController::class, 'store']);
        Route::post('reservations/{reservation}/cancel', [Admin\ReservationController::class, 'cancel']);
        Route::post('reservations/{reservation}/renew', [Admin\ReservationController::class, 'renew']);

        Route::get('customers', [Admin\CustomerController::class, 'index']);
        Route::post('customers', [Admin\CustomerController::class, 'store']);
        Route::patch('customers/{customer}', [Admin\CustomerController::class, 'update']);
        Route::post('customers/{customer}/line-bind-link', [Admin\CustomerController::class, 'issueBindLink']);
        Route::delete('customers/{customer}/line-binding', [Admin\CustomerController::class, 'unbindLine']);
    });
});
