<?php

/*
 * 並發測試用 worker：由 InventoryConcurrencyTest 以獨立 process 啟動。
 * 用法：php allocate_worker.php <variantId> <quantity> <orderId> <startBarrierFile>
 * 輸出：OK / INSUFFICIENT / ERROR <message>
 */

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Services\InventoryService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $variantId, $quantity, $orderId, $barrier] = $argv;

// 等所有 worker 都啟動完成後才同時開始，盡量製造真正的競爭
$deadline = microtime(true) + 10;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(1000);
}

try {
    app(InventoryService::class)->allocate((int) $variantId, (int) $quantity, Order::findOrFail($orderId));
    echo 'OK';
} catch (InsufficientStockException) {
    echo 'INSUFFICIENT';
} catch (Throwable $e) {
    echo 'ERROR '.$e->getMessage();
    exit(1);
}
