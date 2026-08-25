<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-order, decided per product.
 *
 * A pre-order sale is recorded like any other: the SALE movement is written and
 * the balance goes negative, which is exactly what it means — units owed. The
 * ledger stays the only source of truth and a delivery brings the balance back
 * up on its own. This is how Shopify's "continue selling when out of stock" and
 * WooCommerce's backorders behave, and it avoids a second set of books that
 * could disagree with the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('allow_preorder')->default(false)->after('stock_quantity');

            // How far below zero this product may be sold. Null means no cap,
            // which is a deliberate choice an admin has to make: without one, a
            // single scripted buyer can commit the shop to any number of units.
            $table->unsignedInteger('preorder_limit')->nullable()->after('allow_preorder');

            // What the customer is told. A pre-order without a date is just a
            // delay they did not agree to.
            $table->date('preorder_release_at')->nullable()->after('preorder_limit');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['allow_preorder', 'preorder_limit', 'preorder_release_at']);
        });
    }
};
