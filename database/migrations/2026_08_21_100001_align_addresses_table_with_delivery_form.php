<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customer delivery-address form posts division / district / city / address / is_default,
 * but the original addresses table only had type / name / phone / street_address / city / zone.
 * Saving an address therefore failed outright. This aligns the table with the form and keeps
 * the legacy columns around (nullable) so existing rows survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            if (! Schema::hasColumn('addresses', 'division')) {
                $table->string('division')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('addresses', 'district')) {
                $table->string('district')->nullable()->after('division');
            }
            if (! Schema::hasColumn('addresses', 'address')) {
                $table->string('address', 500)->nullable()->after('city');
            }
            if (! Schema::hasColumn('addresses', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('zone');
            }
        });

        // Legacy required columns are no longer collected by the form.
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('phone')->nullable()->change();
            $table->string('street_address')->nullable()->change();
        });

        // Carry any existing data across to the new column.
        DB::table('addresses')
            ->whereNull('address')
            ->whereNotNull('street_address')
            ->update(['address' => DB::raw('street_address')]);
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn(['division', 'district', 'address', 'is_default']);
        });
    }
};
