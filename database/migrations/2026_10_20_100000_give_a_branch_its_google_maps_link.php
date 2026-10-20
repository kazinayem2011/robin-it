<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Google Maps link staff paste for a branch.
 *
 * Kept as pasted, so the form shows it back; the map drawn on the Showrooms
 * page is worked out from it into map_embed_url, which was already here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->text('map_link')->nullable()->after('opening_hours');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('map_link');
        });
    }
};
