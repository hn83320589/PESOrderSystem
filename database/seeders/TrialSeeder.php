<?php

namespace Database\Seeders;

use App\Enums\BillingType;
use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ReservationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 熟客試用資料，對應 docs/TRIAL_SCRIPT.md 的情境 S1–S8。
 *
 *   php artisan migrate:fresh --seed --seeder=TrialSeeder
 *
 * 訂單一律經由 OrderService / PaymentService 建立，庫存、流水帳、PDF、收款紀錄皆與正式流程一致。
 * 日期以「執行當天」為基準，歷史資料放在上個月與上上個月的月中，避開月底跨時區問題。
 */
class TrialSeeder extends Seeder
{
    public const BOSS_EMAIL = 'boss@trial.test';

    public const STAFF_EMAIL = 'staff@trial.test';

    public const TRIAL_PASSWORD = 'trial1234';

    public const MONTHLY_CUSTOMER = '永興水電行';

    public const COD_CUSTOMER = '大發電器行';

    public const UNBOUND_CUSTOMER = '順利水電工程';

    public const LOW_STOCK_PRODUCT = '漏電斷路器';

    public const LOW_STOCK_SPEC = '3P 50A';

    public const LOW_STOCK_QUANTITY = 3;

    private const DEFAULT_STOCK = 200;

    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly ReservationService $reservations,
    ) {}

    public function run(): void
    {
        // 歷史訂單不發 LINE；試用客戶的 LINE userId 也是假的
        config(['services.line.channel_access_token' => null]);

        $staff = $this->createStaff();
        $this->createStock();
        [$yongxing, $dafa, $shunli] = $this->createCustomers();

        $twoMonthsAgo = now()->subMonthsNoOverflow(2)->startOfMonth();
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth();

        // 永興水電行（月結）：上個月 1 張已收、2 張未收 → S6 月結收款
        $this->shippedOrder($yongxing, [['PVC 水管', '1/2"', 20], ['PVC 彎頭', '1/2"', 30], ['生料帶', '12mm', 10]], $twoMonthsAgo->copy()->addDays(14), paid: true);
        $this->shippedOrder($yongxing, [['PVC 水管', '1/2"', 20], ['PVC 三通', '1/2"', 15], ['止水閥', '1/2"', 4]], $lastMonth->copy()->addDays(4), paid: true);
        $this->shippedOrder($yongxing, [['PVC 水管', '3/4"', 25], ['PVC 膠水', '250g', 3]], $lastMonth->copy()->addDays(11));
        $this->shippedOrder($yongxing, [['PVC 水管', '1/2"', 20], ['PVC 彎頭', '1/2"', 30], ['生料帶', '12mm', 10], ['不鏽鋼軟管', '40cm', 6]], $lastMonth->copy()->addDays(19));

        // 大發電器行（貨到付款）：歷史已收；另有一張今天自己從手機送出、待店家確認 → S2
        $this->shippedOrder($dafa, [['單切開關', '白', 20], ['插座', '接地雙插', 20], ['出線盒', '單聯', 40]], $twoMonthsAgo->copy()->addDays(9), paid: true);
        $this->shippedOrder($dafa, [['PVC 電線', '2.0mm', 3], ['單切開關', '白', 10], ['電火布', '黑', 10]], $lastMonth->copy()->addDays(15), paid: true);
        $this->orders->create($dafa, $this->items([['LED 燈管', '4尺 白光', 24], ['LED 崁燈', '12cm 白光', 12]]),
            PaymentMethod::CashOnDelivery, OrderSource::Customer, note: '週五前送到工地', clientRequestId: (string) Str::uuid());

        // 順利水電工程（月結、尚未綁定 LINE）→ S4
        $this->shippedOrder($shunli, [['排水管 PVC', '3"', 10], ['落水頭', '防臭型 2"', 6]], $twoMonthsAgo->copy()->addDays(20), paid: true);

        // 熟客預留：永興 5 天後到期（提醒範圍內）→ S3、S7；大發已過期未處理 → S7
        $this->reservations->create($yongxing, $this->variant('PVC 水管', '3/4"')->id, 30,
            now()->addDays(5)->setTime(18, 0), $staff, '每月固定叫貨');
        $overdue = $this->reservations->create($dafa, $this->variant('單切開關', '白')->id, 10,
            now()->addDay(), $staff, '上次說要再訂');
        $overdue->update(['expires_at' => now()->subHour()]);

        $this->printSummary($shunli);
    }

    private function createStaff(): User
    {
        User::factory()->create(['name' => '老闆', 'email' => self::BOSS_EMAIL, 'password' => self::TRIAL_PASSWORD]);
        User::factory()->create(['name' => '兒子', 'email' => 'son@trial.test', 'password' => self::TRIAL_PASSWORD]);

        return User::factory()->create(['name' => '工讀生', 'email' => self::STAFF_EMAIL, 'password' => self::TRIAL_PASSWORD]);
    }

    private function createStock(): void
    {
        $this->call(CatalogSeeder::class);
        ProductVariant::with('inventory')->get()->each(fn (ProductVariant $v) => $v->inventory->update(['on_hand' => self::DEFAULT_STOCK]));
        $this->variant(self::LOW_STOCK_PRODUCT, self::LOW_STOCK_SPEC)->inventory->update(['on_hand' => self::LOW_STOCK_QUANTITY]);
    }

    /** @return array{0: Customer, 1: Customer, 2: Customer} */
    private function createCustomers(): array
    {
        $bound = fn (string $key) => ['line_user_id' => 'Utrial'.str_pad($key, 27, '0'), 'line_display_name' => null, 'line_bound_at' => now(), 'line_is_friend' => true];

        $yongxing = Customer::create(['name' => self::MONTHLY_CUSTOMER, 'contact_name' => '王老闆', 'phone' => '0912-111-222',
            'address' => '台中市西屯區永福路 88 號', 'billing_type' => BillingType::Monthly]);
        $yongxing->forceFill($bound('yongxing') + ['line_display_name' => '王老闆'])->save();

        $dafa = Customer::create(['name' => self::COD_CUSTOMER, 'contact_name' => '陳師傅', 'phone' => '0922-333-444',
            'address' => '台中市北屯區大連路二段 12 號', 'billing_type' => BillingType::CashOnDelivery]);
        $dafa->forceFill($bound('dafa') + ['line_display_name' => '陳師傅'])->save();

        $shunli = Customer::create(['name' => self::UNBOUND_CUSTOMER, 'contact_name' => '李小姐', 'phone' => '0933-555-666',
            'address' => '台中市南屯區順和路 3 號', 'billing_type' => BillingType::Monthly]);

        return [$yongxing, $dafa, $shunli];
    }

    /**
     * 在指定時間走完 建單 → 確認 → 出貨（→ 收款）。
     *
     * @param  array<int, array{0: string, 1: string, 2: int}>  $lines  [品名, 規格, 數量]
     */
    private function shippedOrder(Customer $customer, array $lines, Carbon $shippedAt, bool $paid = false): Order
    {
        $shippedAt->setTime(10, 0);
        $method = $customer->defaultPaymentMethod();

        Carbon::setTestNow($shippedAt->copy()->subDay());
        $order = $this->orders->create($customer, $this->items($lines), $method, OrderSource::Admin);
        $this->orders->confirm($order);

        Carbon::setTestNow($shippedAt);
        $order = $this->orders->ship($order);

        if ($paid) {
            Carbon::setTestNow($shippedAt->copy()->addDays(3));
            $this->payments->markPaid([$order->payment->id], ['method' => $method, 'paid_at' => now()]);
        }
        Carbon::setTestNow();

        return $order;
    }

    /** @param array<int, array{0: string, 1: string, 2: int}> $lines */
    private function items(array $lines): array
    {
        return array_map(fn ($line) => ['variant_id' => $this->variant($line[0], $line[1])->id, 'quantity' => $line[2]], $lines);
    }

    private function variant(string $product, string $spec): ProductVariant
    {
        return ProductVariant::with('inventory')->whereRelation('product', 'name', $product)->where('spec', $spec)->sole();
    }

    private function printSummary(Customer $unbound): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->info('試用資料已建立，劇本見 docs/TRIAL_SCRIPT.md');
        $this->command->table(['角色', '帳號', '密碼'], [
            ['老闆', self::BOSS_EMAIL, self::TRIAL_PASSWORD],
            ['兒子', 'son@trial.test', self::TRIAL_PASSWORD],
            ['工讀生', self::STAFF_EMAIL, self::TRIAL_PASSWORD],
        ]);
        $this->command->table(['客戶', '本機預覽客戶端（僅 local 環境）'], Customer::whereNotNull('line_user_id')->get()
            ->map(fn (Customer $c) => [$c->name, url("/dev/customer-login/{$c->id}")])->all());
        $this->command->info("「{$unbound->name}」LINE 綁定連結：".$unbound->issueLineBindToken());
    }
}
