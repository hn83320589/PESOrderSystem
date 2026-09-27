<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // LINE 推播與站內通知共用；channel=site 的紀錄即站內通知本身
        Schema::create('notifications_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('type');
            $table->string('title');
            $table->text('body');
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->nullableMorphs('related');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'channel', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_log');
    }
};
