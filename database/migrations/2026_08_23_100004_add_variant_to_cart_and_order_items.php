<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained()->cascadeOnDelete();
        });

        // One line per option, not per product: a shopper buying both the 16GB
        // and the 32GB has two lines, and the old unique(cart_id, product_id)
        // rejected the second one outright.
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'product_id']);
            $table->unique(['cart_id', 'product_id', 'product_variant_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            // Null on the variant side means the line was bought as a single
            // product. Restocking reads this to know which shelf to credit.
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained()->nullOnDelete();

            // Frozen at purchase time, like product_name — the variant may be
            // renamed or removed later and the invoice must still read correctly.
            $table->string('variant_name')->nullable()->after('product_name');

            // How many of this line have already come back, so a second return
            // cannot exceed what was actually bought.
            $table->unsignedInteger('returned_quantity')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'product_id', 'product_variant_id']);
            $table->dropForeign(['product_variant_id']);
            $table->dropColumn('product_variant_id');
            $table->unique(['cart_id', 'product_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_variant_id']);
            $table->dropColumn(['product_variant_id', 'variant_name', 'returned_quantity']);
        });
    }
};
