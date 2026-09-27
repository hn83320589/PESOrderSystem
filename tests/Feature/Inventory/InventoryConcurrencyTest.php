<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * 以多個獨立 PHP process 同時搶購同一規格，驗證不會超賣。
 *
 * 不使用 RefreshDatabase：worker 是獨立 process，必須看得到已提交的資料。
 * 因此改用專屬的暫存資料庫，測完即刪，避免資料殘留影響其他測試：
 * - SQLite：暫存檔（SQLite 寫入本身會序列化，驗證的是防護條件是否正確）
 * - MySQL：暫存 database（真正並行的交易，驗證實際競爭情境）
 */
class InventoryConcurrencyTest extends TestCase
{
    private string $driver;

    /** @var array<string, string> 傳給 worker 的資料庫環境變數 */
    private array $workerEnv;

    private string $barrierFile;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $this->driver = config("database.connections.{$connection}.driver");
        $this->barrierFile = tempnam(sys_get_temp_dir(), 'pes-concurrency-barrier-');
        unlink($this->barrierFile);

        match ($this->driver) {
            'sqlite' => $this->useSqliteFile(),
            'mysql', 'mariadb' => $this->useMysqlDatabase($connection),
            default => $this->markTestSkipped("並發測試不支援 {$this->driver}"),
        };

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        @unlink($this->barrierFile);
        $connection = config('database.default');
        DB::disconnect($connection);

        if ($this->driver === 'sqlite') {
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                @unlink($this->workerEnv['DB_DATABASE'].$suffix);
            }
        } else {
            DB::connection($connection)->statement('DROP DATABASE IF EXISTS `'.$this->workerEnv['DB_DATABASE'].'`');
        }
        parent::tearDown();
    }

    private function useSqliteFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pes-concurrency-').'.sqlite';
        touch($file);
        config(['database.connections.sqlite.database' => $file]);
        DB::purge('sqlite');
        $this->workerEnv = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file];
    }

    private function useMysqlDatabase(string $connection): void
    {
        $config = config("database.connections.{$connection}");
        $database = $config['database'].'_concurrency';
        DB::connection($connection)->statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        config(["database.connections.{$connection}.database" => $database]);
        DB::purge($connection);
        $this->workerEnv = [
            'DB_CONNECTION' => $connection,
            'DB_HOST' => (string) $config['host'],
            'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => $database,
            'DB_USERNAME' => (string) $config['username'],
            'DB_PASSWORD' => (string) $config['password'],
        ];
    }

    public function test_parallel_allocations_never_oversell(): void
    {
        $variant = ProductVariant::factory()->withStock(10)->create();
        $order = Order::factory()->create();
        $workers = 20;

        $pool = Process::pool(function ($pool) use ($workers, $variant, $order) {
            foreach (range(1, $workers) as $i) {
                $pool->path(base_path())
                    ->env($this->workerEnv + ['APP_ENV' => 'testing'])
                    ->timeout(30)
                    ->command([PHP_BINARY, 'tests/Support/allocate_worker.php', $variant->id, 1, $order->id, $this->barrierFile]);
            }
        })->start();

        usleep(300_000);
        touch($this->barrierFile);
        $outputs = collect($pool->wait()->collect())->map(fn ($result) => trim($result->output().$result->errorOutput()));

        $this->assertEmpty($outputs->filter(fn ($o) => ! in_array($o, ['OK', 'INSUFFICIENT'], true))->all(), 'worker 不應出現其他錯誤');
        $this->assertSame(10, $outputs->filter(fn ($o) => $o === 'OK')->count());
        $this->assertSame(10, $outputs->filter(fn ($o) => $o === 'INSUFFICIENT')->count());

        $inventory = $variant->inventory()->first();
        $this->assertSame(10, $inventory->allocated);
        $this->assertSame(0, $inventory->available());
        $this->assertSame(10, InventoryMovement::where('type', InventoryMovementType::Allocate)->count());
    }
}
