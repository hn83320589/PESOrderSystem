<?php

use App\Http\Controllers\Customer\LineAuthController;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// 客戶 LINE 登入與一次性綁定連結
Route::get('/line/bind/{token}', [LineAuthController::class, 'bind'])->name('line.bind');
Route::get('/auth/line', [LineAuthController::class, 'redirect'])->name('line.login');
Route::get('/auth/line/callback', [LineAuthController::class, 'callback'])->name('line.login.callback');

// 僅本機開發：尚未取得 LINE Login 金鑰前，用來以指定客戶身分預覽客戶端
if (app()->environment('local')) {
    Route::get('/dev/customer-login/{customer}', function (Customer $customer) {
        Auth::guard('customer')->login($customer);
        session()->regenerate();
        session([LineAuthController::SESSION_LINE_SUB => $customer->line_user_id]);

        return redirect('/');
    });
}

// 內部管理端 SPA，前端路由由 vue-router 處理
Route::view('/admin/{any?}', 'admin')->where('any', '.*')->name('admin');

// 客戶端 SPA（排除 api/ 開頭，讓未定義的 API 路徑回傳 404 而不是網頁）
Route::view('/{any?}', 'customer')->where('any', '^(?!api/).*')->name('customer');
