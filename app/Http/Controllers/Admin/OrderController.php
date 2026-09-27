<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'customer_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $orders = Order::with('customer', 'payment')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('q'), fn ($q) => $q->where('order_no', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')->startOfDay()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')->endOfDay()))
            ->latest()->latest('id')
            ->paginate(30);

        return OrderResource::collection($orders);
    }

    public function store(Request $request): OrderResource
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            ...$this->itemRules('required'),
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = $this->orders->create(
            Customer::findOrFail($data['customer_id']),
            $data['items'],
            PaymentMethod::from($data['payment_method']),
            OrderSource::Admin,
            $request->user('web'),
            $data['note'] ?? null,
        );

        return $this->resource($order);
    }

    public function show(Order $order): OrderResource
    {
        return $this->resource($order);
    }

    public function update(Request $request, Order $order): OrderResource
    {
        $data = $request->validate([
            'payment_method' => ['sometimes', Rule::enum(PaymentMethod::class)],
            ...$this->itemRules('sometimes'),
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $order = $this->orders->update(
            $order,
            $data['items'] ?? null,
            isset($data['payment_method']) ? PaymentMethod::from($data['payment_method']) : null,
            array_key_exists('note', $data) ? (string) $data['note'] : null,
            $request->user('web'),
        );

        return $this->resource($order);
    }

    public function confirm(Request $request, Order $order): OrderResource
    {
        return $this->resource($this->orders->confirm($order, $request->user('web')));
    }

    public function ship(Request $request, Order $order): OrderResource
    {
        return $this->resource($this->orders->ship($order, $request->user('web')));
    }

    public function expire(Request $request, Order $order): OrderResource
    {
        return $this->resource($this->orders->expire($order, $request->user('web')));
    }

    public function recentForCustomer(Customer $customer): AnonymousResourceCollection
    {
        return OrderResource::collection($this->orders->recentForReorder($customer));
    }

    private function itemRules(string $presence): array
    {
        return [
            'items' => [$presence, 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    private function resource(Order $order): OrderResource
    {
        return new OrderResource($order->load('customer', 'creator', 'items', 'payment'));
    }
}
