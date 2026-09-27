<?php

use Illuminate\Support\Facades\Route;

// 內部管理端 SPA，前端路由由 vue-router 處理
Route::view('/admin/{any?}', 'admin')->where('any', '.*')->name('admin');

// 客戶端 SPA（排除 api/ 開頭，讓未定義的 API 路徑回傳 404 而不是網頁）
Route::view('/{any?}', 'customer')->where('any', '^(?!api/).*')->name('customer');
