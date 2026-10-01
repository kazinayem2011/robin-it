<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The colour of a category's card on the home page (#rrggbb), chosen in the
 * admin. Empty takes the next colour of a fixed palette, so neighbouring
 * cards always differ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('accent_color', 7)->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('accent_color');
        });
    }
};
