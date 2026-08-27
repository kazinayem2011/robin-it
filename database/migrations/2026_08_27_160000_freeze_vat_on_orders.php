<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The VAT on an order, and the rules it was charged under, frozen with it.
 *
 * Rates change and shops switch between inclusive and exclusive pricing. An
 * invoice from two years ago still has to reconcile, and "recompute it from
 * today's settings" is exactly how a set of books stops adding up.
 *
 * `vat_inclusive` is stored as well as the rate because it decides what the
 * amount means: whether it was taken out of the price the customer paid, or
 * added on top of it.
 *
 * Existing orders get 0.00 at a null rate — they were placed before the shop
 * charged VAT, which is the truth rather than a gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('vat_amount', 12, 2)->default(0)->after('discount');
            $table->decimal('vat_rate', 5, 2)->nullable()->after('vat_amount');
            $table->boolean('vat_inclusive')->nullable()->after('vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['vat_amount', 'vat_rate', 'vat_inclusive']);
        });
    }
};
