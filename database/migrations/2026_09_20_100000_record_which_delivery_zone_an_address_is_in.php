<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which rate an address pays, said rather than guessed.
 *
 * Delivery has always been priced by looking for the word "dhaka" in whatever
 * the customer typed as their city. That is fine against a field that holds
 * only a city, and wrong as soon as the address is one line: "Dhaka Road,
 * Chittagong" would be charged the inside-Dhaka rate, and a customer writing
 * ঢাকা would be charged the outside rate. Both cost somebody money.
 *
 * So the zone becomes a choice the customer makes, stored beside the address.
 * Existing rows keep working: the column is null for them and ShippingRates
 * still falls back to reading the city.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            if (! Schema::hasColumn('addresses', 'delivery_zone')) {
                $table->string('delivery_zone', 20)->nullable()->after('zone');
            }
        });

        /*
         * The city stops being required along with it. The form no longer has a
         * box for it — the address is one line — so demanding it would refuse
         * every address saved from checkout from here on. The column stays, and
         * stays populated for every row written before now, because orders and
         * addresses recorded then are still priced from it.
         */
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('city')->nullable()->change();
        });

        /*
         * Backfill from the city, using exactly the rule that has been pricing
         * these addresses all along, so nothing changes price on deploy. Only
         * where the city is filled — a row with no city has nothing to infer
         * from, and null keeps it on the fallback path.
         */
        DB::table('addresses')
            ->whereNull('delivery_zone')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->update([
                'delivery_zone' => DB::raw(
                    "CASE WHEN LOWER(city) LIKE '%dhaka%' THEN 'inside_dhaka' ELSE 'outside_dhaka' END"
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            if (Schema::hasColumn('addresses', 'delivery_zone')) {
                $table->dropColumn('delivery_zone');
            }
        });
    }
};
