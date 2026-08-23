<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a promo target part of the catalogue instead of the whole cart.
 *
 * A scoped coupon discounts only the lines it covers, so "20% off graphics
 * cards" cannot take 20% off a cart that also holds a processor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            // all | products | categories
            $table->string('scope', 20)->default('all')->after('discount_value');
        });

        Schema::create('coupon_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unique(['coupon_id', 'product_id']);
        });

        Schema::create('coupon_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unique(['coupon_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_category');
        Schema::dropIfExists('coupon_product');

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
