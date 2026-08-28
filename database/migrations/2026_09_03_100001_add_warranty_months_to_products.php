<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long a product is covered for.
 *
 * The warranty section has always asked customers for a purchase date and a
 * serial, and the shop had nothing to measure either against — the length of
 * cover lived only in the description, where no code could read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Null means "not stated", which is different from zero: zero is a
            // deliberate "sold as seen".
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('reorder_level');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('warranty_months');
        });
    }
};
