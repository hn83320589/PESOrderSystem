<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('billing_type')->default('cash_on_delivery');
            $table->string('line_user_id')->nullable()->unique();
            $table->string('line_display_name')->nullable();
            $table->timestamp('line_bound_at')->nullable();
            $table->string('line_bind_token', 64)->nullable()->unique();
            $table->timestamp('line_bind_token_expires_at')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
