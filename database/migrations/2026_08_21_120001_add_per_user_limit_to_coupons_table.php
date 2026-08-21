<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons only had a global usage_limit, so a single customer could redeem the
 * same code on every order they placed. This adds a per-customer cap, counted
 * from the coupon_code already recorded on each order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->unsignedInteger('per_user_limit')->nullable()->after('usage_limit');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Redemptions are counted by (coupon_code, user_id) at checkout.
            $table->index(['coupon_code', 'user_id'], 'orders_coupon_user_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_coupon_user_index');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('per_user_limit');
        });
    }
};
