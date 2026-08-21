<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons were validated at checkout but never recorded against the order,
 * so the customer was billed the undiscounted total. Orders now carry the
 * discount actually applied and the code that produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('discount', 10, 2)->default(0)->after('shipping_fee');
            $table->string('coupon_code')->nullable()->after('discount');
            $table->index('status', 'orders_status_index');
            $table->index('session_id', 'orders_session_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_index');
            $table->dropIndex('orders_session_id_index');
            $table->dropColumn(['discount', 'coupon_code']);
        });
    }
};
