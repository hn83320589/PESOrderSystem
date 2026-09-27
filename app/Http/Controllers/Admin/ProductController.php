<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\VariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 商品與規格維護。庫存數量不在此異動，一律走 InventoryController。
 */
class ProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $products = Product::with(['variants' => fn ($q) => $q->orderBy('id'), 'variants.inventory'])
            ->orderBy('category')->orderBy('name')
            ->get();

        return ProductResource::collection($products);
    }

    public function store(Request $request): ProductResource
    {
        $data = $request->validate([
            ...$this->productRules(),
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.spec' => ['required', 'string', 'max:100'],
            'variants.*.price' => ['required', 'integer', 'min:0'],
            'variants.*.sku' => ['nullable', 'string', 'max:50', 'distinct', 'unique:product_variants,sku'],
        ]);

        $product = DB::transaction(function () use ($data) {
            $product = Product::create($data);
            foreach ($data['variants'] as $variant) {
                $product->variants()->create($variant);
            }

            return $product;
        });

        return new ProductResource($product->load('variants.inventory'));
    }

    public function update(Request $request, Product $product): ProductResource
    {
        $product->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:50'],
            'unit' => ['sometimes', 'required', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return new ProductResource($product->load('variants.inventory'));
    }

    public function storeVariant(Request $request, Product $product): VariantResource
    {
        $variant = $product->variants()->create($request->validate([
            'spec' => ['required', 'string', 'max:100'],
            'price' => ['required', 'integer', 'min:0'],
            'sku' => ['nullable', 'string', 'max:50', 'unique:product_variants,sku'],
        ]));

        return new VariantResource($variant->load('inventory'));
    }

    public function updateVariant(Request $request, ProductVariant $variant): VariantResource
    {
        $variant->update($request->validate([
            'spec' => ['sometimes', 'required', 'string', 'max:100'],
            'price' => ['sometimes', 'required', 'integer', 'min:0'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('product_variants', 'sku')->ignore($variant)],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return new VariantResource($variant->load('inventory'));
    }

    private function productRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:50'],
            'unit' => ['required', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
