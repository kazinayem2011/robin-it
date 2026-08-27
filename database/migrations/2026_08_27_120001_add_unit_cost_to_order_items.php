<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each sold unit cost the shop, frozen at the moment of sale.
 *
 * Purchase prices move. The stock ledger knows what was last paid for a unit
 * today, but that tells you nothing about what an order from three months ago
 * actually earned — and once the price has moved, the old figure is gone. Cost
 * is captured the same way `price` already is: written onto the line at
 * checkout and never recalculated.
 *
 * Nullable on purpose. A product that has never come in through a delivery has
 * no known cost, and a guess would be worse than an admission — the same rule
 * the stock valuation already follows.
 *
 * Existing rows stay null: their cost at the time is not recoverable, and
 * inventing one would put a made-up number into a profit figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
