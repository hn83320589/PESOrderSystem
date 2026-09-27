<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingType;
use App\Exports\MonthlyStatementExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function monthly(Request $request): JsonResponse
    {
        [$month, $billingType] = $this->filters($request);

        return response()->json(['data' => $this->reports->monthlySummary($month, $billingType)]);
    }

    public function customerMonthly(Request $request, Customer $customer): JsonResponse
    {
        [$month] = $this->filters($request);
        $orders = $this->reports->customerOrders($month, $customer);
        $summary = $this->reports->monthlySummary($month)['rows']->firstWhere('customer_id', $customer->id);

        return response()->json(['data' => [
            'customer' => ['id' => $customer->id, 'name' => $customer->name],
            'summary' => $summary,
            'orders' => OrderResource::collection($orders)->resolve($request),
        ]]);
    }

    public function exportMonthly(Request $request): BinaryFileResponse
    {
        [$month, $billingType] = $this->filters($request);

        return Excel::download(new MonthlyStatementExport($month, $billingType), '月結對帳_'.$month->format('Y-m').'.xlsx');
    }

    /** @return array{0: Carbon, 1: ?BillingType} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'billing_type' => ['nullable', Rule::enum(BillingType::class)],
        ]);

        return [
            ReportService::monthStart($data['month']),
            isset($data['billing_type']) ? BillingType::from($data['billing_type']) : null,
        ];
    }
}
