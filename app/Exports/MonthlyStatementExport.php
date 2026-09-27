<?php

namespace App\Exports;

use App\Enums\BillingType;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

// laravel-excel 4.x：多工作表匯出需同時實作 Export 標記介面
class MonthlyStatementExport implements Export, WithMultipleSheets
{
    public function __construct(
        private readonly Carbon $monthStart,
        private readonly ?BillingType $billingType = null,
    ) {}

    public function sheets(): array
    {
        $reports = app(ReportService::class);

        return [
            new MonthlySummarySheet($this->monthStart, $reports->monthlySummary($this->monthStart, $this->billingType)),
            new MonthlyDetailSheet($reports->allShippedOrders($this->monthStart, $this->billingType)),
        ];
    }
}
