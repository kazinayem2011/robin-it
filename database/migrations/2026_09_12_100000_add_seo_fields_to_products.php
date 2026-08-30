<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product SEO, which the product page has been faking.
 *
 * Products/Show.jsx passes `product.name` as the page title and
 * `short_description || name` as the meta description, because there was
 * nothing else to pass. That means every product's search result reads like its
 * product listing, and the name that sells a product in a grid is not the
 * sentence that earns a click in Google.
 *
 * The shop being modelled here separates the two, visibly:
 *
 *   <title>            MSI Cyborg 15 Black Edition A13UC Laptop Price in Bangladesh
 *   og:title / name    MSI Cyborg 15 Black Edition A13UC Core i5 13th Gen RTX 3050 …
 *   description        Buy MSI Cyborg 15 … at best price in Bangladesh. Order online …
 *
 * The title is written for a search result, the name for the page. Same columns
 * OpenCart keeps in `product_description`, and the same names `content_pages`
 * already uses here so the two agree.
 *
 * All nullable: a shop with four hundred products is not going to hand-write
 * four hundred meta descriptions, and the page falls back to the name exactly
 * as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('meta_title')->nullable()->after('description');
            $table->string('meta_description', 500)->nullable()->after('meta_title');

            // Singular, as OpenCart names it. Long because it holds the whole
            // spec line — "Core i5 13th Gen RTX 3050 15.6\" FHD Gaming Laptop" —
            // rather than a handful of words.
            $table->string('meta_keyword', 500)->nullable()->after('meta_description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['meta_title', 'meta_description', 'meta_keyword']);
        });
    }
};
