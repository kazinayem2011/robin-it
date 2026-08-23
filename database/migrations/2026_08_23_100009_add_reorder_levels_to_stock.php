<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When to buy more of something.
 *
 * "Low stock" was a single hardcoded 10 across the whole catalogue, which is
 * wrong in both directions: ten cables is nearly out, ten flagship graphics
 * cards is months of inventory. Each product — and each option, since they sell
 * at different rates — carries its own level, falling back to a store-wide
 * default when it has not been set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('reorder_level')->nullable()->after('stock_quantity');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('reorder_level')->nullable()->after('stock_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('reorder_level');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('reorder_level');
        });
    }
};
