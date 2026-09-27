<?php

namespace Database\Seeders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 開發/展示用資料。不使用 WithoutModelEvents：新增規格時需要觸發事件自動建立庫存列。
 */
class DatabaseSeeder extends Seeder
{
    private const CATALOG = [
        ['PVC 水管', '水', '支', ['1/2"', '3/4"', '1"', '1-1/4"', '1-1/2"', '2"'], 60],
        ['PVC 彎頭', '水', '個', ['1/2"', '3/4"', '1"', '1-1/2"', '2"'], 8],
        ['PVC 三通', '水', '個', ['1/2"', '3/4"', '1"', '1-1/2"', '2"'], 10],
        ['PVC 膠水', '水', '罐', ['100g', '250g', '500g', '1kg'], 45],
        ['不鏽鋼水龍頭', '水', '個', ['短', '長', '壁式', '檯式', '廚房用'], 350],
        ['止水閥', '水', '個', ['1/2"', '3/4"', '1"', '1-1/4"', '1-1/2"'], 180],
        ['不鏽鋼軟管', '水', '條', ['30cm', '40cm', '50cm', '60cm', '80cm'], 90],
        ['生料帶', '水', '捲', ['12mm', '19mm', '25mm'], 12],
        ['落水頭', '水', '個', ['2"', '3"', '4"', '防臭型 2"', '防臭型 3"'], 120],
        ['排水管 PVC', '水', '支', ['2"', '3"', '4"', '5"', '6"'], 150],
        ['PVC 電線', '電', '捲', ['1.6mm', '2.0mm', '5.5mm²', '8mm²', '14mm²', '22mm²'], 1200],
        ['VCT 電纜', '電', '捲', ['2C 2.0mm', '2C 3.5mm²', '3C 2.0mm', '3C 3.5mm²', '3C 5.5mm²'], 2800],
        ['單切開關', '電', '個', ['白', '灰', '附指示燈', '防水型', '夜光型'], 75],
        ['雙切開關', '電', '個', ['白', '灰', '附指示燈', '防水型', '夜光型'], 95],
        ['插座', '電', '個', ['單插', '雙插', '接地雙插', 'USB 插座', '防水型'], 85],
        ['無熔絲開關', '電', '個', ['1P 15A', '1P 20A', '1P 30A', '2P 20A', '2P 30A', '2P 50A'], 320],
        ['漏電斷路器', '電', '個', ['2P 20A', '2P 30A', '2P 40A', '3P 30A', '3P 50A'], 980],
        ['電線管 PVC', '電', '支', ['16mm', '20mm', '25mm', '32mm', '40mm'], 40],
        ['電線管接頭', '電', '個', ['16mm', '20mm', '25mm', '32mm', '40mm'], 5],
        ['出線盒', '電', '個', ['單聯', '雙聯', '三聯', '防水型', '八角盒'], 15],
        ['LED 燈管', '電', '支', ['2尺 白光', '2尺 黃光', '4尺 白光', '4尺 黃光', '4尺 自然光'], 110],
        ['LED 崁燈', '電', '個', ['9cm 白光', '9cm 黃光', '12cm 白光', '12cm 黃光', '15cm 白光'], 260],
        ['電火布', '電', '捲', ['黑', '白', '紅', '藍', '綠'], 15],
        ['束線帶', '電', '包', ['10cm', '15cm', '20cm', '30cm'], 35],
        ['壓接端子', '電', '包', ['1.25mm²', '2mm²', '5.5mm²', '8mm²', '14mm²'], 80],
    ];

    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => '老闆',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);
        User::factory()->create(['name' => '工讀生', 'email' => 'staff@example.com', 'password' => 'password']);

        foreach (self::CATALOG as [$name, $category, $unit, $specs, $basePrice]) {
            $product = Product::create(compact('name', 'category', 'unit') + ['is_active' => true]);
            foreach ($specs as $i => $spec) {
                $variant = $product->variants()->create([
                    'spec' => $spec,
                    'sku' => sprintf('P%02d-%02d', $product->id, $i + 1),
                    'price' => (int) round($basePrice * (1 + $i * 0.15)),
                ]);
                $variant->inventory->update(['on_hand' => fake()->numberBetween(20, 300)]);
            }
        }

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
