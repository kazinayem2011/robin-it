<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A product can be listed in more than one category.
 *
 * One `category_id` was enough while the tree described what a thing *is*. It
 * stopped being enough once the tree also described who *makes* it: an Asus
 * gaming laptop belongs under Laptop > Gaming Laptop > Asus and under Laptop >
 * All Laptop > Asus, and with a single column it can only be in one, so it
 * vanishes from the other. This is the same shape as OpenCart's
 * `product_to_category`, which is what the shop being modelled here runs on.
 *
 * `products.category_id` stays, and stays required. It is now the *primary*
 * category — the one that gives the product its breadcrumb and its canonical
 * URL — and it is mirrored into this table so a single query answers "what is
 * listed under this category" without having to union the two.
 *
 * Keeping it also means every existing query still returns something sensible
 * while the callers are moved across one at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            // Listing the same product twice in one category is never
            // meaningful, and would double it in every count.
            $table->unique(['product_id', 'category_id']);

            // The catalogue reads this the other way round: given a category,
            // which products. The unique index above starts with product_id and
            // cannot serve that.
            $table->index('category_id');
        });

        // Backfill, so the pivot is authoritative from the moment it exists
        // rather than after some later save. Chunked because the placeholder
        // catalogue is already over a thousand rows and a shop's is bigger.
        DB::table('products')
            ->select('id', 'category_id')
            ->whereNotNull('category_id')
            ->orderBy('id')
            ->chunkById(500, function ($products) {
                DB::table('category_product')->insertOrIgnore(
                    $products->map(fn ($p) => [
                        'product_id' => $p->id,
                        'category_id' => $p->category_id,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
    }
};
