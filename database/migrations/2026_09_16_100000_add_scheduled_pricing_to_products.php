<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make a discount end by itself, and let a product require a minimum order.
 *
 * A sale currently ends when somebody remembers to clear `discount_price`. That
 * is fine for a permanent markdown and useless for an Eid offer, which has to
 * stop at midnight whether or not anyone is at a desk — and a sale that
 * outlives its advertised end date is one the shop is legally holding to.
 *
 * Both dates nullable, and both null means "on, until changed", which is
 * exactly what every existing discount already is. Nothing needs backfilling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('discount_starts_at')->nullable()->after('discount_price');
            $table->timestamp('discount_ends_at')->nullable()->after('discount_starts_at');

            /*
             * Some things are not sold singly — thermal paste by the sachet,
             * cable by the metre, screws by the bag. Without this the shop
             * either loses money on a one-unit order or handles it by hand.
             */
            $table->unsignedSmallInteger('min_order_quantity')->default(1)->after('stock_quantity');

            /*
             * How often the page has been opened. Not analytics — a "most
             * viewed" shelf, and a signal for which of two similar products to
             * feature. Counted rather than derived because nothing else records
             * a view.
             */
            $table->unsignedBigInteger('views_count')->default(0)->after('is_featured');
        });

        // A sale that has started and not ended is the hot path — every
        // catalogue query filters on it once discounts are scheduled.
        Schema::table('products', function (Blueprint $table) {
            $table->index(['discount_starts_at', 'discount_ends_at'], 'products_discount_window_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_discount_window_index');
            $table->dropColumn([
                'discount_starts_at', 'discount_ends_at',
                'min_order_quantity', 'views_count',
            ]);
        });
    }
};
