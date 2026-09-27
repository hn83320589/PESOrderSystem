<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Models\Customer;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function __construct(private readonly ReservationService $reservations) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(ReservationStatus::class)],
            'customer_id' => ['nullable', 'integer'],
        ]);

        $reservations = Reservation::with('customer', 'variant.product', 'variant.inventory')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->orderBy('expires_at')
            ->paginate(50);

        return ReservationResource::collection($reservations);
    }

    public function store(Request $request): ReservationResource
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'product_variant_id' => ['required', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'expires_at' => ['required', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $reservation = $this->reservations->create(
            Customer::findOrFail($data['customer_id']),
            $data['product_variant_id'],
            $data['quantity'],
            Carbon::parse($data['expires_at']),
            $request->user('web'),
            $data['note'] ?? null,
        );

        return $this->resource($reservation);
    }

    public function cancel(Request $request, Reservation $reservation): ReservationResource
    {
        $this->reservations->cancel($reservation, $request->user('web'));

        return $this->resource($reservation->refresh());
    }

    public function renew(Request $request, Reservation $reservation): ReservationResource
    {
        $data = $request->validate(['expires_at' => ['required', 'date', 'after:now']]);

        $next = $this->reservations->renew($reservation, Carbon::parse($data['expires_at']), $request->user('web'));

        return $this->resource($next);
    }

    private function resource(Reservation $reservation): ReservationResource
    {
        return new ReservationResource($reservation->load('customer', 'variant.product', 'variant.inventory'));
    }
}
