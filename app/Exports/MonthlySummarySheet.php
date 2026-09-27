<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
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

/**
 * WithStrictNullComparison：未實作時 laravel-excel 會把 0 視為空值寫成空白格，
 * 對帳單上「0」與「空白」意義不同，必須保留。
 */
class MonthlySummarySheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithFreezePane, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    private const MONEY = '#,##0';

    /** @param array{rows: Collection, totals: array<string, int>} $summary */
    public function __construct(private readonly Carbon $monthStart, private readonly array $summary) {}

    public function title(): string
    {
        return $this->monthStart->format('Y-m').' 月結彙總';
    }

    public function headings(): array
    {
        return ['客戶', '結帳方式', '出貨單數', '本期出貨金額', '本期已收', '本期未收', '前期未收', '累計應收'];
    }

    public function array(): array
    {
        $rows = $this->summary['rows']->map(fn (array $r) => [
            $r['customer_name'], $r['billing_type_label'], $r['order_count'], $r['shipped_total'],
            $r['paid_total'], $r['unpaid_total'], $r['previous_unpaid'], $r['outstanding'],
        ])->all();

        // 合計列用公式，老闆在 Excel 裡修改數字時會自動重算
        $lastDataRow = count($rows) + 1;
        $sum = fn (string $col) => $lastDataRow >= 2 ? "=SUM({$col}2:{$col}{$lastDataRow})" : 0;
        $rows[] = ['合計', '', $sum('C'), $sum('D'), $sum('E'), $sum('F'), $sum('G'), $sum('H')];

        return $rows;
    }

    public function columnFormats(): array
    {
        return ['D' => self::MONEY, 'E' => self::MONEY, 'F' => self::MONEY, 'G' => self::MONEY, 'H' => self::MONEY];
    }

    public function freezePane(): string
    {
        return 'A2';
    }

    public function styles(Worksheet $sheet): ?array
    {
        $lastRow = $this->summary['rows']->count() + 2;

        return [1 => ['font' => ['bold' => true]], $lastRow => ['font' => ['bold' => true]]];
    }
}
