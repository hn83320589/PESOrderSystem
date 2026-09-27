<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 可用量 = on_hand - reserved - allocated
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('on_hand')->default(0);   // 倉庫實際數量
            $table->unsignedInteger('reserved')->default(0);  // 熟客預留中
            $table->unsignedInteger('allocated')->default(0); // 已下單未出貨
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->integer('on_hand_change')->default(0);
            $table->integer('reserved_change')->default(0);
            $table->integer('allocated_change')->default(0);
            $table->unsignedInteger('on_hand_after');
            $table->unsignedInteger('reserved_after');
            $table->unsignedInteger('allocated_after');
            $table->nullableMorphs('reference');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory');
    }
};
