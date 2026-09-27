<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    /**
     * 標記收款。多筆時全部成功或全部不處理（月結客戶一次付清多張單）。
     *
     * @param  array<int, int>  $paymentIds
     * @param  array{method: PaymentMethod, paid_at?: ?DateTimeInterface, check_no?: ?string, check_due_date?: ?string, note?: ?string}  $data
     * @return Collection<int, Payment>
     */
    public function markPaid(array $paymentIds, array $data, ?User $user = null): Collection
    {
        return DB::transaction(function () use ($paymentIds, $data, $user) {
            $payments = Payment::with('order')->whereKey($paymentIds)->lockForUpdate()->get();

            $problems = [];
            foreach ($paymentIds as $id) {
                $payment = $payments->firstWhere('id', $id);
                if (! $payment) {
                    $problems[] = "收款紀錄 #{$id} 不存在";
                } elseif ($payment->order->status === OrderStatus::Expired) {
                    $problems[] = "訂單 {$payment->order->order_no} 已失效，不需收款";
                } elseif ($payment->status === PaymentStatus::Paid) {
                    $problems[] = "訂單 {$payment->order->order_no} 已經標記收款";
                }
            }
            if ($problems) {
                throw ValidationException::withMessages(['payment_ids' => $problems]);
            }

            $isCheck = $data['method'] === PaymentMethod::Check;
            foreach ($payments as $payment) {
                $payment->update([
                    'status' => PaymentStatus::Paid,
                    'method' => $data['method'],
                    'paid_at' => $data['paid_at'] ?? now(),
                    'check_no' => $isCheck ? $data['check_no'] : null,
                    'check_due_date' => $isCheck ? $data['check_due_date'] : null,
                    'note' => $data['note'] ?? $payment->note,
                    'recorded_by' => $user?->id,
                ]);
            }

            return $payments;
        });
    }

    /** 撤銷誤標的收款 */
    public function markUnpaid(Payment $payment, ?User $user = null): Payment
    {
        if ($payment->status !== PaymentStatus::Paid) {
            throw ValidationException::withMessages(['status' => '此筆尚未收款']);
        }

        $payment->update([
            'status' => PaymentStatus::Unpaid,
            'paid_at' => null,
            'check_no' => null,
            'check_due_date' => null,
            'recorded_by' => $user?->id,
        ]);

        return $payment;
    }
}
