<?php

namespace App\Http\Controllers\Customer;

use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\CustomerOrderResource;
use App\Models\Customer;
use App\Services\OrderPdfService;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * 客戶自己的訂單。所有查詢由登入客戶的關聯出發，他人訂單一律 404。
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $this->customer($request)->orders()
            ->with('items', 'payment')
            ->latest()->latest('id')
            ->paginate(20);

        return CustomerOrderResource::collection($orders);
    }

    public function show(Request $request, int $order): CustomerOrderResource
    {
        return new CustomerOrderResource($this->customer($request)->orders()->with('items', 'payment')->findOrFail($order));
    }

    public function store(Request $request): CustomerOrderResource
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'request_id' => ['required', 'uuid'],
        ]);
        $customer = $this->customer($request);

        $order = $this->orders->create(
            $customer,
            $data['items'],
            isset($data['payment_method']) ? PaymentMethod::from($data['payment_method']) : $customer->defaultPaymentMethod(),
            OrderSource::Customer,
            note: $data['note'] ?? null,
            clientRequestId: $data['request_id'],
        );

        return new CustomerOrderResource($order->load('items', 'payment'));
    }

    public function pdf(Request $request, int $order, OrderPdfService $pdf): Response
    {
        $order = $this->customer($request)->orders()->findOrFail($order);

        return response($pdf->contentForPrint($order, customerId: $order->customer_id), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$order->order_no}.pdf\"",
        ]);
    }

    public function recent(Request $request): AnonymousResourceCollection
    {
        return CustomerOrderResource::collection($this->orders->recentForReorder($this->customer($request)));
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }
}
