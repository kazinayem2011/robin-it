<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The manufacturer's part number, per option.
 *
 * A phone in 4GB/64GB and 4GB/128GB is two part numbers from the maker. The
 * way StarTech shows it: choosing an option swaps the MPN on the page and
 * nothing else of the text, which is written to cover every choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('mpn', 120)->nullable()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('mpn');
        });
    }
};
