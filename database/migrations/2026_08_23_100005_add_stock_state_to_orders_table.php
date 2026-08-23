<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guards against restocking the same order twice.
 *
 * `updateOrderStatus` only checked whether the order was cancelled a moment ago,
 * so cancelled -> pending -> cancelled put the units back on the shelf twice and
 * created stock out of nothing. These flags make the release a one-way latch that
 * has to be explicitly re-armed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Set when a cancellation returns the reserved units to the shelf;
            // cleared only when the order is reopened and stock is re-reserved.
            $table->timestamp('stock_released_at')->nullable()->after('status');

            // Set when a delivered order has been processed as a return.
            $table->timestamp('stock_returned_at')->nullable()->after('stock_released_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['stock_released_at', 'stock_returned_at']);
        });
    }
};
