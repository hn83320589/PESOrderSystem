<?php

namespace Tests\Feature\Inventory;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\ProductVariant;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReservationService $reservations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reservations = app(ReservationService::class);
    }

    private function makeReservation(int $quantity = 5, ?\DateTimeInterface $expiresAt = null, int $stock = 20): Reservation
    {
        $variant = ProductVariant::factory()->withStock($stock)->create();

        return $this->reservations->create(
            Customer::factory()->create(),
            $variant->id,
            $quantity,
            $expiresAt ?? now()->addDays(30),
            User::factory()->create(),
        );
    }

    public function test_create_holds_stock_for_the_customer(): void
    {
        $reservation = $this->makeReservation(quantity: 5, stock: 20);

        $this->assertSame(ReservationStatus::Active, $reservation->status);
        $inventory = $reservation->variant->inventory;
        $this->assertSame(5, $inventory->reserved);
        $this->assertSame(15, $inventory->available());
    }

    public function test_create_fails_when_stock_is_insufficient(): void
    {
        $this->expectException(InsufficientStockException::class);
        $this->makeReservation(quantity: 30, stock: 20);
    }

    public function test_create_rejects_past_expiry(): void
    {
        $this->expectException(ValidationException::class);
        $this->makeReservation(expiresAt: now()->subDay());
    }

    public function test_cancel_releases_stock(): void
    {
        $reservation = $this->makeReservation(quantity: 5, stock: 20);

        $this->reservations->cancel($reservation);

        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
        $this->assertSame(20, $reservation->variant->inventory()->first()->available());
    }

    public function test_only_active_reservations_can_be_cancelled(): void
    {
        $reservation = $this->makeReservation();
        $this->reservations->cancel($reservation);

        $this->expectException(ValidationException::class);
        $this->reservations->cancel($reservation->fresh());
    }

    public function test_expire_due_releases_only_expired_reservations(): void
    {
        $due = $this->makeReservation(quantity: 5, expiresAt: now()->addDay());
        $notDue = $this->makeReservation(quantity: 5, expiresAt: now()->addDays(3));

        $this->travel(2)->days();
        $count = $this->reservations->expireDue();

        $this->assertSame(1, $count);
        $this->assertSame(ReservationStatus::Expired, $due->fresh()->status);
        $this->assertSame(0, $due->variant->inventory()->first()->reserved);
        $this->assertSame(ReservationStatus::Active, $notDue->fresh()->status);
    }

    public function test_expire_due_twice_does_not_release_twice(): void
    {
        $reservation = $this->makeReservation(quantity: 5, expiresAt: now()->addDay(), stock: 20);
        $this->travel(2)->days();

        $this->reservations->expireDue();
        $this->assertSame(0, $this->reservations->expireDue());

        $inventory = $reservation->variant->inventory()->first();
        $this->assertSame(0, $inventory->reserved);
        $this->assertSame(20, $inventory->available());
    }

    public function test_remind_due_notifies_once_within_seven_days(): void
    {
        $soon = $this->makeReservation(expiresAt: now()->addDays(6));
        $later = $this->makeReservation(expiresAt: now()->addDays(10));

        $this->assertSame(1, $this->reservations->remindDue());
        $this->assertSame(0, $this->reservations->remindDue(), '同一筆預留只提醒一次');

        $this->assertNotNull($soon->fresh()->reminded_at);
        $this->assertNull($later->fresh()->reminded_at);
        $notice = NotificationLog::where('customer_id', $soon->customer_id)->where('channel', NotificationChannel::Site)->sole();
        $line = NotificationLog::where('customer_id', $soon->customer_id)->where('channel', NotificationChannel::Line)->sole();
        $this->assertSame(NotificationStatus::Skipped, $line->status, '未綁定 LINE 的客戶只收站內通知');
        $this->assertSame('reservation_expiring', $notice->type);
        $this->assertStringContainsString($soon->variant->product->name, $notice->body);
    }

    public function test_renew_creates_next_period_with_same_customer_and_quantity(): void
    {
        $reservation = $this->makeReservation(quantity: 5, stock: 20);

        $next = $this->reservations->renew($reservation, now()->addDays(60));

        $this->assertNotSame($reservation->id, $next->id);
        $this->assertSame($reservation->customer_id, $next->customer_id);
        $this->assertSame(5, $next->quantity);
        $this->assertSame(10, $next->variant->inventory()->first()->reserved);
    }

    public function test_commands_run_expire_and_remind(): void
    {
        $this->makeReservation(expiresAt: now()->addDays(3));

        $this->artisan('reservations:remind')->expectsOutputToContain('1')->assertSuccessful();
        $this->travel(4)->days();
        $this->artisan('reservations:expire')->expectsOutputToContain('1')->assertSuccessful();
        $this->assertSame(0, Reservation::where('status', ReservationStatus::Active)->count());
    }
}
