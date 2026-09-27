<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $customers = Customer::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $keyword = '%'.$request->string('q').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $keyword)
                    ->orWhere('contact_name', 'like', $keyword)
                    ->orWhere('phone', 'like', $keyword));
            })
            ->orderBy('name')
            ->get();

        return CustomerResource::collection($customers);
    }

    public function store(Request $request): CustomerResource
    {
        return new CustomerResource(Customer::create($request->validate($this->rules())));
    }

    public function update(Request $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validate($this->rules(partial: true)));

        return new CustomerResource($customer);
    }

    public function issueBindLink(Customer $customer): JsonResponse
    {
        $url = $customer->issueLineBindToken();

        return response()->json(['data' => [
            'url' => $url,
            'expires_at' => $customer->line_bind_token_expires_at,
        ]]);
    }

    public function unbindLine(Customer $customer): CustomerResource
    {
        $customer->unbindLine();

        return new CustomerResource($customer);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'billing_type' => [$required, Rule::enum(BillingType::class)],
            'note' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
