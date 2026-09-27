<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 客戶自存的常用組合（第二階段）；只存規格與數量，價格一律取當下售價
        Schema::create('favorite_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->timestamps();
        });

        Schema::create('favorite_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('favorite_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorite_order_items');
        Schema::dropIfExists('favorite_orders');
    }
};
