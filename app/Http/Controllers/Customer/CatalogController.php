<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function index(): JsonResponse
    {
        $products = Product::where('is_active', true)
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('id'), 'variants.inventory'])
            ->orderBy('category')->orderBy('name')
            ->get()
            ->filter(fn (Product $p) => $p->variants->isNotEmpty())
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'category' => $p->category,
                'unit' => $p->unit,
                'variants' => $p->variants->map(fn (ProductVariant $v) => [
                    'id' => $v->id,
                    'spec' => $v->spec,
                    'price' => $v->price,
                    'stock_status' => $this->stockStatus($v->inventory->available()),
                ])->values(),
            ])
            ->values();

        return response()->json(['data' => $products]);
    }

    private function stockStatus(int $available): string
    {
        return match (true) {
            $available <= 0 => 'out',
            $available < Inventory::LOW_STOCK_THRESHOLD => 'low',
            default => 'in_stock',
        };
    }
}
