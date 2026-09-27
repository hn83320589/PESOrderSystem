<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Order;

/**
 * 給客戶看的通知文字。客群多為中高齡：短句、一行一件事、金額與帳號寫清楚。
 */
class CustomerMessages
{
    private const MAX_LISTED_ITEMS = 10;

    /** @return array{0: string, 1: string} [標題, 內容] */
    public function orderConfirmed(Order $order): array
    {
        $order->loadMissing('items');
        $lines = ["訂單編號：{$order->order_no}", '訂購品項：'];

        foreach ($order->items->take(self::MAX_LISTED_ITEMS) as $item) {
            $lines[] = "・{$item->product_name} {$item->spec} × {$item->quantity} {$item->unit}";
        }
        if ($order->items->count() > self::MAX_LISTED_ITEMS) {
            $lines[] = sprintf('…等共 %d 項', $order->items->count());
        }

        $lines[] = '金額：$'.number_format($order->total_amount);
        $lines[] = '付款方式：'.$order->payment_method->label();

        $bank = config('shop.bank');
        if ($order->payment_method === PaymentMethod::BankTransfer && filled($bank['account'])) {
            $lines[] = "匯款帳號：{$bank['name']} {$bank['account']}".($bank['account_name'] ? "（戶名：{$bank['account_name']}）" : '');
        }
        if (filled(config('shop.phone'))) {
            $lines[] = '有問題請來電：'.config('shop.phone');
        }

        return ['【'.config('shop.name').'】您的訂單已確認', implode("\n", $lines)];
    }
}
