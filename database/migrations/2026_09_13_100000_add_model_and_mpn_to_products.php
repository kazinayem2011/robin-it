<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two codes a customer actually uses to identify a machine.
 *
 * The shop being modelled prints both at the top of its Key Features list,
 * ahead of the processor:
 *
 *     MPN: 9S7-15K112-2423
 *     Model: Cyborg 15 Black Edition A13UC
 *
 * `barcode` already exists and is neither of these. It is the number on the
 * box, scanned at a delivery or a stock count, unique to this shop's copy of
 * the product. The MPN is the manufacturer's own part number — the string a
 * customer pastes into Google to check they are buying the same revision — and
 * the model is what they say out loud in the shop.
 *
 * Not unique. Two sellers legitimately list the same MPN, and a shop that
 * stocks both the 8GB and 16GB build of one model has the same model string on
 * two products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('model')->nullable()->after('name');
            $table->string('mpn', 120)->nullable()->after('model');

            // "Which one is the 9S7-15K112-2423?" is a question staff ask at a
            // delivery, so it has to be answerable without a table scan.
            $table->index('mpn');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['mpn']);
            $table->dropColumn(['model', 'mpn']);
        });
    }
};
