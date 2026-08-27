<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the promo code was, frozen with the order that used it.
 *
 * Orders recorded the code and the money — `coupon_code` and `discount` — and
 * nothing about the terms. The amount was therefore always right, but nobody
 * could say *why*: edit SAVE10 from 10% to 90%, or delete it, and the order
 * still reads "SAVE10, ৳100 off" with no way to check that ৳100 was correct.
 *
 * For a dispute, a refund, or an audit, that is the question being asked.
 *
 * Nullable, and null on existing rows: their terms at the time are not
 * recoverable, and reading today's coupon back onto an old order would be
 * worse than admitting the gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('coupon_discount_type', 20)->nullable()->after('coupon_code');
            $table->decimal('coupon_discount_value', 12, 2)->nullable()->after('coupon_discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['coupon_discount_type', 'coupon_discount_value']);
        });
    }
};
