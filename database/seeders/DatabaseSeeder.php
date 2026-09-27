<?php

namespace Database\Seeders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 開發/展示用資料。不使用 WithoutModelEvents：新增規格時需要觸發事件自動建立庫存列。
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => '老闆',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);
        User::factory()->create(['name' => '工讀生', 'email' => 'staff@example.com', 'password' => 'password']);

        $this->call(CatalogSeeder::class);
        ProductVariant::with('inventory')->get()->each(
            fn (ProductVariant $v) => $v->inventory->update(['on_hand' => fake()->numberBetween(20, 300)]),
        );

        $customers = Customer::factory(10)->create();
        $customers->first()->update(['name' => '測試水電行（已綁 LINE）']);
        Customer::whereKey($customers->first()->id)->update([
            'line_user_id' => 'Udemo0000000000000000000000000000',
            'line_display_name' => '陳老闆',
            'line_bound_at' => now(),
        ]);

        $variants = ProductVariant::with('product')->get();
        foreach ($customers as $customer) {
            // 已出貨的歷史訂單：供「老樣子」功能展示，不影響目前庫存
            foreach (range(1, fake()->numberBetween(3, 8)) as $n) {
                $createdAt = now()->subDays(fake()->numberBetween(3, 90));
                $order = Order::create([
                    'order_no' => $createdAt->format('Ymd').'-'.str_pad((string) (Order::count() + 1), 3, '0', STR_PAD_LEFT),
                    'customer_id' => $customer->id,
                    'status' => OrderStatus::Shipped,
                    'payment_method' => fake()->randomElement(PaymentMethod::cases()),
                    'source' => OrderSource::Admin,
                    'created_by' => $admin->id,
                    'confirmed_at' => $createdAt,
                    'shipped_at' => $createdAt->copy()->addDay(),
                ]);
                $order->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

                foreach ($variants->random(fake()->numberBetween(2, 5)) as $variant) {
                    OrderItem::factory()->for($order)->for($variant, 'variant')->create([
                        'quantity' => fake()->numberBetween(1, 30),
                    ]);
                }

                $total = $order->items()->sum('subtotal');
                $order->update(['total_amount' => $total]);
                $paid = fake()->boolean(70);
                $order->payment()->create([
                    'customer_id' => $customer->id,
                    'method' => $order->payment_method,
                    'amount' => $total,
                    'status' => $paid ? PaymentStatus::Paid : PaymentStatus::Unpaid,
                    'paid_at' => $paid ? $createdAt->copy()->addDays(5) : null,
                ]);
            }
        }
    }
}
