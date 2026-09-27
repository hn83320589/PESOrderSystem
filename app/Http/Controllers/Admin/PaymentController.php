<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'customer_id' => ['nullable', 'integer'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ]);

        $payments = Payment::with('order', 'customer')
            ->whereHas('order', fn ($q) => $q->where('status', '!=', OrderStatus::Expired))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->string('method')))
            ->latest('id')
            ->paginate(50);

        return PaymentResource::collection($payments);
    }

    public function markPaid(Request $request, Payment $payment): PaymentResource
    {
        $this->payments->markPaid([$payment->id], $this->paidData($request), $request->user('web'));

        return new PaymentResource($payment->fresh()->load('order', 'customer'));
    }

    public function bulkMarkPaid(Request $request): JsonResponse
    {
        $request->validate([
            'payment_ids' => ['required', 'array', 'min:1'],
            'payment_ids.*' => ['integer', 'distinct'],
        ]);

        $paid = $this->payments->markPaid($request->input('payment_ids'), $this->paidData($request), $request->user('web'));

        return response()->json(['data' => ['count' => $paid->count(), 'amount' => (int) $paid->sum('amount')]]);
    }

    public function markUnpaid(Request $request, Payment $payment): PaymentResource
    {
        $this->payments->markUnpaid($payment, $request->user('web'));

        return new PaymentResource($payment->fresh()->load('order', 'customer'));
    }

    private function paidData(Request $request): array
    {
        $data = $request->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'check_no' => ['required_if:method,check', 'nullable', 'string', 'max:50'],
            'check_due_date' => ['required_if:method,check', 'nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $data['method'] = PaymentMethod::from($data['method']);
        $data['paid_at'] = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null;

        return $data;
    }
}
