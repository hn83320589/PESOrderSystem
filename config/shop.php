<?php

// 店家資訊：LINE 通知與 PDF 訂單上顯示（匯款帳號為已定案需求）
return [
    'name' => env('SHOP_NAME', '水電材料行'),
    'phone' => env('SHOP_PHONE'),
    'address' => env('SHOP_ADDRESS'),
    'bank' => [
        'name' => env('SHOP_BANK_NAME'),
        'account' => env('SHOP_BANK_ACCOUNT'),
        'account_name' => env('SHOP_BANK_ACCOUNT_NAME'),
    ],
];
