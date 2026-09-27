<?php

namespace App\Http\Controllers\Customer;

use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\CustomerOrderResource;
use App\Models\Customer;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 客戶確認預留：依定案不自動產生訂單，由客戶按「確認下單」才成立。
 */
class ReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reservations = $this->customer($request)->reservations()
            ->with('variant.product')
            ->where('status', ReservationStatus::Active)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'product_name' => $r->variant->product->name,
                'spec' => $r->variant->spec,
                'unit' => $r->variant->product->unit,
                'quantity' => $r->quantity,
                'price' => $r->variant->price,
                'expires_at' => $r->expires_at,
            ]);

        return response()->json(['data' => $reservations]);
    }

    public function confirm(Request $request, int $reservation, OrderService $orders): CustomerOrderResource
    {
        $data = $request->validate([
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'request_id' => ['required', 'uuid'],
        ]);
        $customer = $this->customer($request);
        $reservation = $customer->reservations()->findOrFail($reservation);

        if ($reservation->status !== ReservationStatus::Active || $reservation->expires_at->isPast()) {
            throw ValidationException::withMessages(['reservation' => '這筆預留已到期或已處理，請直接下單或聯絡店家']);
        }

        $order = $orders->create(
            $customer,
            [['variant_id' => $reservation->product_variant_id, 'quantity' => $reservation->quantity]],
            isset($data['payment_method']) ? PaymentMethod::from($data['payment_method']) : $customer->defaultPaymentMethod(),
            OrderSource::Customer,
            clientRequestId: $data['request_id'],
        );

        return new CustomerOrderResource($order->load('items', 'payment'));
    }

    private function customer(Request $request): Customer
    {
        return $request->user('customer');
    }
}
