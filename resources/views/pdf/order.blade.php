<!DOCTYPE html>
<html lang="zh-Hant-TW">
<head>
<meta charset="utf-8">
<style>
    body { font-family: notosanstc; font-size: 11pt; color: #111; }
    .header td { border: none; padding: 0; vertical-align: top; }
    h1 { font-size: 18pt; margin: 0; }
    .shop { font-size: 9.5pt; color: #444; }
    .meta { width: 100%; margin-top: 8pt; border-collapse: collapse; }
    .meta td { border: none; padding: 2pt 0; font-size: 10.5pt; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 8pt; }
    table.items th, table.items td { border: 0.5pt solid #333; padding: 4pt 5pt; }
    table.items th { background: #eee; font-weight: bold; }
    thead { display: table-header-group; }
    .r { text-align: right; }
    .total td { font-weight: bold; font-size: 12.5pt; }
    .bank { margin-top: 10pt; padding: 6pt 8pt; border: 1pt solid #000; font-weight: bold; }
    .note { margin-top: 8pt; }
</style>
</head>
<body>
<table class="header" width="100%">
    <tr>
        <td><h1>{{ $shop['name'] }}　訂貨單</h1></td>
        <td class="r shop">
            @if ($shop['phone'])電話：{{ $shop['phone'] }}<br>@endif
            @if ($shop['address']){{ $shop['address'] }}@endif
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td>單號：<strong>{{ $order->order_no }}</strong></td>
        <td>下單日期：{{ $order->created_at->format('Y/m/d H:i') }}</td>
    </tr>
    <tr>
        <td>客戶：{{ $order->customer->name }}@if ($order->customer->contact_name)（{{ $order->customer->contact_name }}）@endif</td>
        <td>電話：{{ $order->customer->phone }}</td>
    </tr>
    @if ($order->customer->address)
        <tr><td colspan="2">送貨地址：{{ $order->customer->address }}</td></tr>
    @endif
</table>

<table class="items">
    <thead>
        <tr>
            <th width="6%">#</th><th>品名</th><th width="16%">規格</th>
            <th class="r" width="11%">數量</th><th class="r" width="12%">單價</th><th class="r" width="14%">小計</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($order->items as $i => $item)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $item->product_name }}</td>
                <td>{{ $item->spec }}</td>
                <td class="r">{{ $item->quantity }} {{ $item->unit }}</td>
                <td class="r">${{ number_format($item->unit_price) }}</td>
                <td class="r">${{ number_format($item->subtotal) }}</td>
            </tr>
        @endforeach
        <tr class="total">
            <td colspan="5" class="r">合計</td>
            <td class="r">${{ number_format($order->total_amount) }}</td>
        </tr>
    </tbody>
</table>

<div class="meta">付款方式：{{ $order->payment_method->label() }}</div>
@if ($order->payment_method === \App\Enums\PaymentMethod::BankTransfer && $shop['bank']['account'])
    <div class="bank">
        匯款帳號：{{ $shop['bank']['name'] }} {{ $shop['bank']['account'] }}
        @if ($shop['bank']['account_name'])（戶名：{{ $shop['bank']['account_name'] }}）@endif
    </div>
@endif

@if ($order->note)
    <div class="note">備註：{{ $order->note }}</div>
@endif
</body>
</html>
