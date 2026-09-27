<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 客戶端送出時產生的 UUID：重複點擊或網路重送只會成立一張訂單
            $table->uuid('client_request_id')->nullable()->after('source');
            // 以客戶為範圍，避免猜到他人的 request id 而取得他人訂單
            $table->unique(['customer_id', 'client_request_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['customer_id', 'client_request_id']);
            $table->dropColumn('client_request_id');
        });
    }
};
