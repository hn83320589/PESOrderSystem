<?php

namespace App\Models;

use App\Enums\BillingType;
use App\Enums\PaymentMethod;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/**
 * 客戶透過 LINE Login 登入（auth guard: customer），沒有密碼。
 */
#[Fillable(['name', 'contact_name', 'phone', 'address', 'billing_type', 'note', 'is_active'])]
#[Hidden(['remember_token', 'line_bind_token'])]
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'billing_type' => BillingType::class,
            'is_active' => 'boolean',
            'line_bound_at' => 'datetime',
            'line_is_friend' => 'boolean',
            'line_bind_token_expires_at' => 'datetime',
        ];
    }

    public const LINE_BIND_TOKEN_DAYS = 7;

    /**
     * 產生一次性 LINE 綁定連結，重新產生會使舊連結失效。
     */
    public function issueLineBindToken(): string
    {
        $this->forceFill([
            'line_bind_token' => Str::random(48),
            'line_bind_token_expires_at' => now()->addDays(self::LINE_BIND_TOKEN_DAYS),
        ])->save();

        return url("/line/bind/{$this->line_bind_token}");
    }

    public function unbindLine(): void
    {
        $this->forceFill([
            'line_user_id' => null,
            'line_display_name' => null,
            'line_bound_at' => null,
            'remember_token' => null,
        ])->save();
    }

    /** 依結帳方式預設的付款方式：月結→匯款、貨到付款→現金 */
    public function defaultPaymentMethod(): PaymentMethod
    {
        return $this->billing_type === BillingType::Monthly ? PaymentMethod::BankTransfer : PaymentMethod::CashOnDelivery;
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }
}
