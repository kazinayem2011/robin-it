<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The questions a shelf asks about a product, and the answers it allows.
 *
 * product_specifications already holds a spec sheet — group, name, value — and
 * it is the wrong shape to filter on. Its values are prose written for someone
 * reading one product: "8 (4 Performance cores, 4 Efficient cores)",
 * "3.4GHz to 4.6GHz". Filtering on that column would make every distinct string
 * its own checkbox, so "15.6 Inch", '15.6"' and "15.6 inch" become three
 * filters matching one product each.
 *
 * A facet needs a controlled value: something two products can share exactly.
 * So the sheet stays as it is, for reading, and this sits beside it, for
 * narrowing.
 *
 * Three kinds of question, because a shop's filters are not all one shape:
 *
 *   enum    one answer from a curated list — Wi-Fi 6, Dual Band, IPS
 *   number  a real number, shown as named bands — "301 Mbps to 750 Mbps"
 *   flags   many answers at once — USB Port, Parental Controls, Mesh Support
 *
 * A design with only the first cannot express a speed range or a feature list,
 * which is most of what a router page is made of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Shown after the number: Mbps, inch, Hz. Null for enum and flags.
            $table->string('unit')->nullable();
            $table->string('input_type')->default('enum');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('slug');
            /*
             * What the band covers, for a numeric attribute. Held on the value
             * rather than on the product so "751 Mbps to 1200 Mbps" is one row
             * a shopper ticks, while the bounds stay available for sorting and
             * for deciding which band a new product falls into.
             */
            $table->decimal('range_from', 12, 2)->nullable();
            $table->decimal('range_to', 12, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['attribute_id', 'slug']);
        });

        /*
         * Which shelf asks which question.
         *
         * Attached to the product-type category and inherited downward. That
         * matters more here than it would elsewhere: the tree has 1,392
         * categories and mixes shelves with makers — MSI and Vention are
         * categories — so attaching per leaf would mean re-declaring the router
         * questions under every router brand.
         */
        Schema::create('attribute_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->unique(['attribute_id', 'category_id']);
        });

        // Many per product, so a flag set works without a second table.
        Schema::create('attribute_value_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained()->cascadeOnDelete();

            $table->unique(['product_id', 'attribute_value_id']);
            // The direction the facet counts run: given a set of values, which
            // products carry them.
            $table->index(['attribute_value_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_value_product');
        Schema::dropIfExists('attribute_category');
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attributes');
    }
};
