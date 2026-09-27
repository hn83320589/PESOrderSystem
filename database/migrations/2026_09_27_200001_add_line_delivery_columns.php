<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications_log', function (Blueprint $table) {
            // LINE X-Line-Retry-Key：重試時沿用同一組，避免客戶收到重複訊息
            $table->uuid('retry_key')->nullable()->after('status');
        });

        Schema::table('customers', function (Blueprint $table) {
            // 由 webhook follow/unfollow 更新；null 表示尚未收到事件
            $table->boolean('line_is_friend')->nullable()->after('line_bound_at');
        });
    }

    public function down(): void
    {
        Schema::table('notifications_log', fn (Blueprint $table) => $table->dropColumn('retry_key'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('line_is_friend'));
    }
};
