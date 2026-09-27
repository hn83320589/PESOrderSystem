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
 * 使用檔案型 SQLite（in-memory 資料庫無法跨 process 共用）。
 */
class InventoryConcurrencyTest extends TestCase
{
    private string $databaseFile;

    private string $barrierFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseFile = tempnam(sys_get_temp_dir(), 'pes-concurrency-').'.sqlite';
        $this->barrierFile = $this->databaseFile.'.start';
        touch($this->databaseFile);

        config(['database.connections.sqlite.database' => $this->databaseFile]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        foreach (['', '-wal', '-shm', '-journal', '.start'] as $suffix) {
            @unlink($this->databaseFile.$suffix);
        }
        parent::tearDown();
    }

    public function test_parallel_allocations_never_oversell(): void
    {
        $variant = ProductVariant::factory()->withStock(10)->create();
        $order = Order::factory()->create();
        $workers = 20;

        $pool = Process::pool(function ($pool) use ($workers, $variant, $order) {
            foreach (range(1, $workers) as $i) {
                $pool->path(base_path())
                    ->env(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->databaseFile, 'APP_ENV' => 'testing'])
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
