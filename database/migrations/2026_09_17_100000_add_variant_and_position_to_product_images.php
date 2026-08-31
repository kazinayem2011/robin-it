<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give a product's photos an owner and an order.
 *
 * `product_images` could already hold several rows per product, but nothing
 * wrote more than one: the admin form had a single path field, so every product
 * shipped with exactly one photo and a customer could not turn the box around.
 *
 * Two things were missing before it could hold a gallery. An order — insertion
 * order is not display order, and re-uploading one photo would otherwise send
 * it to the end. And an owner: an option's photo lived in a single `image_url`
 * column on the variant, so a colour could have one shot and no more.
 *
 * A null product_variant_id means the photo belongs to the product itself,
 * which is what every existing row is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('position')->default(0)->after('is_primary');

            // The product page asks for one product's gallery, and the variant
            // picker asks for one option's, on every product view.
            $table->index(['product_id', 'product_variant_id', 'position'], 'product_images_gallery_index');
        });

        // Existing rows are a single photo each; give them a defined order
        // rather than leaving every one of them at position 0.
        DB::table('product_images')->orderBy('id')->chunkById(500, function ($images) {
            foreach ($images as $image) {
                DB::table('product_images')
                    ->where('id', $image->id)
                    ->update(['position' => $image->is_primary ? 0 : 1]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropIndex('product_images_gallery_index');
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn('position');
        });
    }
};
