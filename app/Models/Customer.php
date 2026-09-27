<?php

namespace App\Models;

use App\Enums\BillingType;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

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
            'line_bind_token_expires_at' => 'datetime',
        ];
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
