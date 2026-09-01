<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A source of stock that is not a supplier.
 *
 * Stock could enter two ways: a delivery received under Purchasing, and a
 * quantity typed on the product form when the product was first entered. The
 * second existed only to describe what was already on the shelf on the day the
 * shop started keeping books — and it was a second write path, with no cost
 * against it, no paperwork behind it and nothing to look up later.
 *
 * It becomes a delivery like any other, received from a source of its own. A
 * supplier is now either someone the shop buys from, or the standing entry that
 * represents "already here when we started".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('kind', 20)->default('trade')->after('name')->index();
        });

        // The one opening-balance source. Seeded rather than left to be created
        // by hand, so receiving an opening balance never depends on somebody
        // having remembered to make it.
        DB::table('suppliers')->insert([
            'name' => 'Opening balance',
            'kind' => 'opening',
            'note' => 'Stock already on the shelf when the shop started keeping books. Not a supplier.',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('suppliers')->where('kind', 'opening')->delete();

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
