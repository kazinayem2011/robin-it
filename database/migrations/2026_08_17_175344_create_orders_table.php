<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id')->nullable(); // For guest checkout
            $table->string('order_number')->unique();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('shipping_fee', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->string('status')->default('pending'); // pending, processing, shipped, delivered, cancelled

            // Payment info
            $table->string('payment_method')->default('COD'); // COD only for now as requested
            $table->string('payment_status')->default('unpaid');

            // Snapshot of address at time of order
            $table->json('shipping_address');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
