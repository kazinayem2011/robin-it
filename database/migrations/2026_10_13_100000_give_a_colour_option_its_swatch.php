<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The colour an option is, as a swatch.
 *
 * "Blue Titanium" is a name; a shopper picking between four titaniums wants to
 * see them. The product form picks the colour, and every place a shopper
 * chooses an option shows it as a round swatch beside the name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('swatch', 7)->nullable()->after('mpn');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('swatch');
        });
    }
};
