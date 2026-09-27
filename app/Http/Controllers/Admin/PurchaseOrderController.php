<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PurchaseService;
use App\Services\ReorderSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseService $purchases) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(PurchaseOrderStatus::class)],
            'supplier_id' => ['nullable', 'integer'],
        ]);

        $orders = PurchaseOrder::with('supplier', 'items')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->latest()->latest('id')
            ->paginate(30);

        return PurchaseOrderResource::collection($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'note' => ['nullable', 'string', 'max:1000'],
            'receive_now' => ['sometimes', 'boolean'],
        ]);

        $po = $this->purchases->create(
            Supplier::findOrFail($data['supplier_id']),
            $data['items'],
            $request->user('web'),
            receiveNow: (bool) ($data['receive_now'] ?? false),
            note: $data['note'] ?? null,
        );

        // 「當場到貨」會重新讀取進貨單而失去 wasRecentlyCreated，明確回傳 201
        return $this->resource($po)->response()->setStatusCode(201);
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return $this->resource($purchaseOrder);
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return $this->resource($this->purchases->receive($purchaseOrder, $request->user('web')));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return $this->resource($this->purchases->cancel($purchaseOrder, $request->user('web')));
    }

    public function suggestions(ReorderSuggestionService $suggestions): JsonResponse
    {
        return response()->json(['data' => $suggestions->suggestions()]);
    }

    private function resource(PurchaseOrder $po): PurchaseOrderResource
    {
        return new PurchaseOrderResource($po->load('supplier', 'items', 'creator', 'receiver'));
    }
}
