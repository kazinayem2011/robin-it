<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The number printed on the box.
 *
 * Counting a shelf and receiving a delivery both meant finding each product in
 * a list by name, which for a shop with three near-identical sticks of RAM is
 * where a stock take goes wrong — and it is slow enough that counts get put
 * off, which is worse.
 *
 * A handheld scanner is a keyboard: it types the code and presses Enter. So
 * the whole feature is having somewhere to put the code and being able to look
 * it up, which is what this column is.
 *
 * Nullable and unique. Most of a computer shop's stock has a manufacturer
 * barcode, some has none, and two products sharing one is always a mistake —
 * scanning it would be a coin toss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 64)->nullable()->unique()->after('slug');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            // Variants carry their own: 16GB and 32GB of the same stick are
            // different boxes with different numbers on them.
            $table->string('barcode', 64)->nullable()->unique()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
            $table->dropColumn('barcode');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
            $table->dropColumn('barcode');
        });
    }
};
