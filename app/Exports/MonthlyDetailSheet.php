<?php

namespace App\Exports;

use App\Models\Order;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithFreezePane;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MonthlyDetailSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithFreezePane, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    /** @param Collection<int, Order> $orders */
    public function __construct(private readonly Collection $orders) {}

    public function title(): string
    {
        return '出貨明細';
    }

    public function headings(): array
    {
        return ['客戶', '出貨日', '單號', '品名', '規格', '數量', '單位', '單價', '小計', '付款方式', '收款狀態'];
    }

    public function array(): array
    {
        return $this->orders->flatMap(fn (Order $order) => $order->items->map(fn ($item) => [
            $order->customer->name,
            $order->shipped_at->format('Y/m/d'),
            $order->order_no,
            $item->product_name,
            $item->spec,
            $item->quantity,
            $item->unit,
            $item->unit_price,
            $item->subtotal,
            $order->payment?->method->label(),
            $order->payment?->status->label(),
        ]))->all();
    }

    public function columnFormats(): array
    {
        return ['H' => '#,##0', 'I' => '#,##0'];
    }

    public function freezePane(): string
    {
        return 'A2';
    }

    public function styles(Worksheet $sheet): ?array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
