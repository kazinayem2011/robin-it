<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buy more, pay less per unit.
 *
 * Distinct from `discount_price`, which is one price for everybody. This is the
 * trade counter: ten cables at 180৳ each, fifty at 160৳. A shop that sells to
 * other shops cannot express that with a single column, and works around it by
 * quoting over the phone — which means the price is not on the site, so the
 * order is not placed on the site.
 *
 * Tiers are per product and per quantity, with the same optional window as a
 * scheduled discount, because a trade offer runs to a date too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // "From this many units". The tier that applies is the highest one
            // the quantity reaches.
            $table->unsignedInteger('min_quantity');
            $table->decimal('price', 10, 2);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            // Two tiers starting at the same quantity is not a cheaper deal, it
            // is an ambiguity — whichever the database returned first would win.
            $table->unique(['product_id', 'min_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_discounts');
    }
};
