<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FavoriteOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 客戶常用組合（第二階段，給願意自己整理的客戶；不影響「照上次叫的貨」流程）。
 * 只存規格與數量；帶入時一律以當下售價、是否停售、是否有貨重新判斷。
 */
class FavoriteOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $favorites = $this->customer($request)->favoriteOrders()
            ->with('items.variant.product', 'items.variant.inventory')
            ->latest('id')
            ->get()
            ->map(fn (FavoriteOrder $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'items' => $f->items->map(fn ($item) => [
                    'variant_id' => $item->product_variant_id,
                    'product_name' => $item->variant->product->name,
                    'spec' => $item->variant->spec,
                    'unit' => $item->variant->product->unit,
                    'quantity' => $item->quantity,
                    'current' => [
                        'price' => $item->variant->price,
                        'orderable' => $item->variant->is_active && $item->variant->product->is_active,
                        'in_stock' => $item->variant->inventory->available() > 0,
                    ],
                ])->values(),
            ]);

        return response()->json(['data' => $favorites]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        $customer = $this->customer($request);

        $count = $customer->favoriteOrders()->count();
        if ($count >= FavoriteOrder::MAX_PER_CUSTOMER) {
            throw ValidationException::withMessages(['name' => '常用組合最多 '.FavoriteOrder::MAX_PER_CUSTOMER.' 組，請先刪掉不用的']);
        }

        $quantities = collect($data['items'])->groupBy('variant_id')->map(fn ($rows) => $rows->sum('quantity'));
        $favorite = DB::transaction(function () use ($customer, $data, $count, $quantities) {
            $favorite = $customer->favoriteOrders()->create(['name' => filled($data['name'] ?? null) ? $data['name'] : '常用 '.($count + 1)]);
            foreach ($quantities as $variantId => $quantity) {
                $favorite->items()->create(['product_variant_id' => $variantId, 'quantity' => $quantity]);
            }

            return $favorite;
        });

        return response()->json(['data' => ['id' => $favorite->id, 'name' => $favorite->name]], 201);
    }

    public function destroy(Request $request, int $favorite): Response
    {
        $this->customer($request)->favoriteOrders()->findOrFail($favorite)->delete();

        return response()->noContent();
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }
}
