<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Four things a product page needs that the schema could not say, each taken
 * off a live competitor page rather than invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            /*
             * Key Features: the curated bullet list above the fold.
             *
             * Not short_description, which validation caps at 500 characters
             * and which renders as one plain string. This is HTML, six or seven
             * bullets, written to sell — it opens with the MPN and the model
             * and closes with a link into the specification tab. A truncated
             * description cannot do that job.
             */
            $table->text('key_features')->nullable()->after('short_description');

            /*
             * A discount that only applies to one way of paying — "1,500৳
             * Discount on Checkout", shown beside the cash option and not the
             * instalment one.
             *
             * Distinct from discount_price, which is unconditional and belongs
             * to the product. This is conditional and belongs to the payment
             * method, so it cannot be folded into the same column without
             * lying to whoever chooses EMI.
             */
            $table->decimal('checkout_discount', 10, 2)->nullable()->after('discount_price');

            /*
             * Instalments. Widespread in Bangladesh and currently promised in
             * siteConfig.js — "0% EMI Up to 36 Months" — while nothing in the
             * application implements it, which is a claim the shop cannot keep.
             *
             * Per product, because a 4,000৳ mouse does not qualify and a
             * 130,000৳ laptop does.
             */
            $table->boolean('emi_available')->default(false)->after('checkout_discount');
            $table->unsignedTinyInteger('emi_max_months')->nullable()->after('emi_available');

            /*
             * What to say when the shelf is empty.
             *
             * Availability has been a boolean derived from stock_quantity, so
             * an empty shelf can only ever read "Out of Stock". A real shop
             * needs to distinguish "Pre-Order", "2-3 Days", "Call for Price"
             * and "Discontinued" — the difference between a sale deferred and a
             * sale lost. In stock is still in stock; this is only consulted at
             * zero.
             */
            $table->string('out_of_stock_status', 60)->nullable()->after('stock_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'key_features', 'checkout_discount', 'emi_available',
                'emi_max_months', 'out_of_stock_status',
            ]);
        });
    }
};
