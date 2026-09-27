<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\InventoryResource;
use App\Http\Resources\VariantResource;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'low_stock' => ['nullable', 'integer', 'min:0'],
        ]);

        $variants = ProductVariant::with('product', 'inventory')
            ->when($request->filled('q'), function ($query) use ($request) {
                $keyword = '%'.$request->string('q').'%';
                $query->where(fn ($q) => $q->where('spec', 'like', $keyword)
                    ->orWhere('sku', 'like', $keyword)
                    ->orWhereRelation('product', 'name', 'like', $keyword));
            })
            ->when($request->filled('low_stock'), fn ($query) => $query->whereHas(
                'inventory',
                fn ($q) => $q->whereRaw('on_hand < reserved + allocated + ?', [$request->integer('low_stock')]),
            ))
            ->orderBy('product_id')->orderBy('id')
            ->get();

        return VariantResource::collection($variants);
    }

    public function adjust(Request $request, ProductVariant $variant, InventoryService $inventory): InventoryResource
    {
        $data = $request->validate([
            'delta' => ['required', 'integer', 'not_in:0'],
            'note' => ['required', 'string', 'max:255'],
        ]);

        $inventory->adjust($variant->id, $data['delta'], $request->user('web'), $data['note']);

        return new InventoryResource($variant->inventory()->first());
    }

    public function movements(ProductVariant $variant): AnonymousResourceCollection
    {
        $movements = $variant->movements()->with('user')->latest('id')->paginate(50);

        return InventoryMovementResource::collection($movements);
    }
}
