<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A spec value as long as the form lets it be.
 *
 * The form accepts 2,000 characters and the column held 255, so a real phone's
 * "Display features" row — StarTech's runs to over 300 — saved as a server
 * error on MySQL and the whole product with it. The test suite runs on SQLite,
 * which does not enforce a VARCHAR's length, so nothing caught it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_specifications', function (Blueprint $table) {
            $table->text('value')->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_specifications', function (Blueprint $table) {
            $table->string('value')->change();
        });
    }
};
