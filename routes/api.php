<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Customer;
use App\Http\Controllers\Line;
use Illuminate\Support\Facades\Route;

/*
 * 內部管理端 API（guard: web）
 */
Route::prefix('admin')->group(function () {
    Route::post('login', [Admin\AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:web')->group(function () {
        Route::post('logout', [Admin\AuthController::class, 'logout']);
        Route::get('me', [Admin\AuthController::class, 'me']);
        Route::get('dashboard', Admin\DashboardController::class);

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
        Route::get('customers/{customer}/recent-orders', [Admin\OrderController::class, 'recentForCustomer']);

        Route::get('orders', [Admin\OrderController::class, 'index']);
        Route::post('orders', [Admin\OrderController::class, 'store']);
        Route::get('orders/{order}', [Admin\OrderController::class, 'show']);
        Route::patch('orders/{order}', [Admin\OrderController::class, 'update']);
        Route::post('orders/{order}/confirm', [Admin\OrderController::class, 'confirm']);
        Route::post('orders/{order}/ship', [Admin\OrderController::class, 'ship']);
        Route::post('orders/{order}/expire', [Admin\OrderController::class, 'expire']);
        Route::get('orders/{order}/pdf', [Admin\OrderController::class, 'pdf']);

        Route::get('payments', [Admin\PaymentController::class, 'index']);
        Route::post('payments/bulk-mark-paid', [Admin\PaymentController::class, 'bulkMarkPaid']);
        Route::post('payments/{payment}/mark-paid', [Admin\PaymentController::class, 'markPaid']);
        Route::post('payments/{payment}/mark-unpaid', [Admin\PaymentController::class, 'markUnpaid']);

        Route::get('notifications', [Admin\NotificationController::class, 'index']);
        Route::post('notifications/{notification}/resend', [Admin\NotificationController::class, 'resend']);

        Route::get('reports/monthly', [Admin\ReportController::class, 'monthly']);
        Route::get('reports/monthly/export', [Admin\ReportController::class, 'exportMonthly']);
        Route::get('reports/monthly/{customer}', [Admin\ReportController::class, 'customerMonthly']);
    });
});

/*
 * 客戶端 API（guard: customer）。所有查詢一律從登入客戶本身出發，不接受外部傳入的 customer_id
 */
Route::prefix('customer')->middleware(['auth:customer', 'customer.bound'])->group(function () {
    Route::post('logout', [Customer\LineAuthController::class, 'logout']);
    Route::get('me', [Customer\AccountController::class, 'me']);
    Route::get('products', [Customer\CatalogController::class, 'index']);

    Route::get('orders', [Customer\OrderController::class, 'index']);
    Route::post('orders', [Customer\OrderController::class, 'store'])->middleware('throttle:20,1');
    Route::get('orders/{order}', [Customer\OrderController::class, 'show']);
    Route::get('orders/{order}/pdf', [Customer\OrderController::class, 'pdf']);
    Route::get('recent-orders', [Customer\OrderController::class, 'recent']);

    Route::get('reservations', [Customer\ReservationController::class, 'index']);
    Route::post('reservations/{reservation}/confirm', [Customer\ReservationController::class, 'confirm'])->middleware('throttle:20,1');

    Route::get('notifications', [Customer\NotificationController::class, 'index']);
    Route::post('notifications/read-all', [Customer\NotificationController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [Customer\NotificationController::class, 'read']);
});

/*
 * LINE 平台 webhook（以 X-Line-Signature 驗證來源，不使用 session）
 */
Route::post('line/webhook', Line\WebhookController::class)->name('line.webhook');
