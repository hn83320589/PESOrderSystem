<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplierController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SupplierResource::collection(Supplier::orderByDesc('is_active')->orderBy('name')->get());
    }

    public function store(Request $request): SupplierResource
    {
        return new SupplierResource(Supplier::create($request->validate($this->rules('required'))));
    }

    public function update(Request $request, Supplier $supplier): SupplierResource
    {
        $supplier->update($request->validate($this->rules('sometimes')));

        return new SupplierResource($supplier);
    }

    private function rules(string $presence): array
    {
        return [
            'name' => [$presence, 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
