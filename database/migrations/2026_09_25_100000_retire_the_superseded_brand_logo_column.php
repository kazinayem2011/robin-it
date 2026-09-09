<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns for one logo, and the shop only ever filled one.
 *
 * `logo` came with the original brands table; `logo_path` was added a fortnight
 * later and is what the admin writes, what the mega menu reads, and what the
 * storefront strip draws. `logo` was left behind holding nothing — every one of
 * the 28 rows is null, and it is not on the model's fillable list, so nothing
 * can put anything in it.
 *
 * Kept as a pair it is worse than useless: the next person to read the schema
 * has to work out which of the two is the real one, and has a fifty per cent
 * chance of writing to the dead one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brands', 'logo')) {
            return;
        }

        /*
         * Refuses rather than discards. The column is empty on every
         * installation seen so far, but "empty here" is not "empty
         * everywhere", and a dropped column takes its contents with it.
         */
        $holding = DB::table('brands')->whereNotNull('logo')->count();

        if ($holding > 0) {
            throw new RuntimeException(
                "brands.logo holds {$holding} value(s) on this database. Copy them into "
                .'logo_path and clear the column before running this migration.'
            );
        }

        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('logo');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('logo')->nullable();
        });
    }
};
