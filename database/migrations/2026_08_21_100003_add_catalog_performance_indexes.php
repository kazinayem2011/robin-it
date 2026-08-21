<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every storefront query filters on is_active, and most also filter by category
 * or brand. Without these the catalogue does a full table scan per request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['is_active', 'category_id'], 'products_active_category_index');
            $table->index(['is_active', 'brand_id'], 'products_active_brand_index');
            $table->index(['is_active', 'is_featured'], 'products_active_featured_index');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->index(['parent_id', 'is_active'], 'categories_parent_active_index');
        });

        Schema::table('product_reviews', function (Blueprint $table) {
            $table->index(['product_id', 'is_approved'], 'product_reviews_product_approved_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_active_category_index');
            $table->dropIndex('products_active_brand_index');
            $table->dropIndex('products_active_featured_index');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_parent_active_index');
        });

        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropIndex('product_reviews_product_approved_index');
        });
    }
};
