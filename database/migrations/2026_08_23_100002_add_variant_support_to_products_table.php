<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // When true, stock lives on the variants and products.stock_quantity
            // is only the maintained sum of them.
            $table->boolean('has_variants')->default(false)->after('stock_quantity');

            // The option axes, e.g. ["Capacity", "Speed"]. Every variant of this
            // product carries exactly these keys.
            $table->json('variant_attributes')->nullable()->after('has_variants');

            $table->index('has_variants');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['has_variants']);
            $table->dropColumn(['has_variants', 'variant_attributes']);
        });
    }
};
