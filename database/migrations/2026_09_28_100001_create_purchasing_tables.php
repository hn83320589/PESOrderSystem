<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_no')->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('ordered');
            // 進價常有小數（小零件單價不足 1 元），進貨側金額以 2 位小數儲存
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('spec');
            $table->string('unit', 20);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            // 移動加權平均成本；null 表示尚無進貨成本資料
            $table->decimal('avg_cost', 12, 2)->nullable()->after('price');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // 出貨當下的平均成本快照，用於毛利；第一階段的舊單為 null
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('avg_cost'));
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
