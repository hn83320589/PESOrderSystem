<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 熟客預留額度：建立時佔住庫存，到期未下單自動釋放。
 * 依已定案決策，不自動產生訂單，只在到期前 7 天提醒客戶。
 */
class ReservationService
{
    public const REMIND_DAYS_BEFORE = 7;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CustomerNotifier $notifier,
    ) {}

    public function create(
        Customer $customer,
        int $variantId,
        int $quantity,
        DateTimeInterface $expiresAt,
        ?User $user = null,
        ?string $note = null,
    ): Reservation {
        if (Carbon::instance($expiresAt)->isPast()) {
            throw ValidationException::withMessages(['expires_at' => '到期時間必須晚於現在']);
        }

        return DB::transaction(function () use ($customer, $variantId, $quantity, $expiresAt, $user, $note) {
            $reservation = Reservation::create([
                'customer_id' => $customer->id,
                'product_variant_id' => $variantId,
                'quantity' => $quantity,
                'expires_at' => $expiresAt,
                'status' => ReservationStatus::Active,
                'created_by' => $user?->id,
                'note' => $note,
            ]);
            $this->inventory->reserve($variantId, $quantity, $reservation, $user);

            return $reservation;
        });
    }

    public function cancel(Reservation $reservation, ?User $user = null): void
    {
        if (! $this->closeIfActive($reservation, ReservationStatus::Cancelled, $user)) {
            throw ValidationException::withMessages(['status' => '只有預留中的項目可以取消']);
        }
    }

    /** 以相同客戶、規格、數量建立下一期預留（取代「頻率規則」自動產生） */
    public function renew(Reservation $reservation, DateTimeInterface $expiresAt, ?User $user = null): Reservation
    {
        return $this->create(
            $reservation->customer,
            $reservation->product_variant_id,
            $reservation->quantity,
            $expiresAt,
            $user,
            $reservation->note,
        );
    }

    /**
     * 客戶下單時消耗其有效預留。回傳可由預留轉為訂單佔用的數量；
     * 訂購量少於預留量時，剩餘預留直接釋放（預留的用途即為這次叫貨）。
     * 需在呼叫端的交易中、於 InventoryService::allocate 之前執行。
     */
    public function consumeForOrder(Customer $customer, int $variantId, int $quantity, Order $order, ?User $user = null): int
    {
        $active = Reservation::where('customer_id', $customer->id)
            ->where('product_variant_id', $variantId)
            ->where('status', ReservationStatus::Active)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get();

        $fromReserved = 0;
        foreach ($active as $reservation) {
            $claimed = Reservation::whereKey($reservation->id)
                ->where('status', ReservationStatus::Active)
                ->update(['status' => ReservationStatus::Fulfilled, 'order_id' => $order->id, 'updated_at' => now()]);
            if ($claimed === 0) {
                continue;
            }

            $used = min($quantity - $fromReserved, $reservation->quantity);
            $fromReserved += $used;
            $leftover = $reservation->quantity - $used;
            if ($leftover > 0) {
                $this->inventory->releaseReservation($variantId, $leftover, $reservation, $user);
            }
        }

        return $fromReserved;
    }

    /** @return int 本次釋放的筆數 */
    public function expireDue(): int
    {
        $due = Reservation::where('status', ReservationStatus::Active)
            ->where('expires_at', '<=', now())
            ->get();

        return $due->filter(fn (Reservation $r) => $this->closeIfActive($r, ReservationStatus::Expired))->count();
    }

    /** @return int 本次提醒的筆數 */
    public function remindDue(): int
    {
        $due = Reservation::with('customer', 'variant.product')
            ->where('status', ReservationStatus::Active)
            ->whereNull('reminded_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(self::REMIND_DAYS_BEFORE))
            ->get();

        $sent = 0;
        foreach ($due as $reservation) {
            // 先搶標記再發送，排程重複執行時不會重複提醒
            $claimed = Reservation::whereKey($reservation->id)->whereNull('reminded_at')->update(['reminded_at' => now()]);
            if ($claimed === 0) {
                continue;
            }

            $variant = $reservation->variant;
            $this->notifier->notify(
                $reservation->customer,
                'reservation_expiring',
                '預留商品即將到期',
                sprintf(
                    '您預留的「%s %s」%d %s 將於 %s 到期，請在到期前下單確認；逾期未確認將自動釋放給其他客戶。',
                    $variant->product->name,
                    $variant->spec,
                    $reservation->quantity,
                    $variant->product->unit,
                    $reservation->expires_at->format('Y/m/d H:i'),
                ),
                $reservation,
            );
            $sent++;
        }

        return $sent;
    }

    /**
     * 以條件式更新「搶下」狀態轉換，只有成功轉換的那一方會釋放庫存，
     * 避免取消與到期排程同時處理同一筆而重複釋放。
     */
    private function closeIfActive(Reservation $reservation, ReservationStatus $to, ?User $user = null): bool
    {
        return DB::transaction(function () use ($reservation, $to, $user) {
            $claimed = Reservation::whereKey($reservation->id)
                ->where('status', ReservationStatus::Active)
                ->update(['status' => $to, 'updated_at' => now()]);
            if ($claimed === 0) {
                return false;
            }

            $this->inventory->releaseReservation($reservation->product_variant_id, $reservation->quantity, $reservation, $user);

            return true;
        });
    }
}
