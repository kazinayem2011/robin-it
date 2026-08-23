<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variants let one product carry several sellable configurations — 16GB vs 32GB,
 * 1TB vs 2TB — each with its own price and its own stock.
 *
 * A product is either single or variant, never both: `products.has_variants`
 * decides which level actually owns the stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Human label built from the options, e.g. "32GB / 6000MHz".
            $table->string('name');
            $table->string('sku')->nullable()->unique();

            // {"Capacity": "32GB", "Speed": "6000MHz"} — the axes are defined on
            // the parent product so every variant shares the same keys.
            $table->json('options')->nullable();

            // Null price means "inherit the parent product's price", so a simple
            // colour variant does not have to restate the money.
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('discount_price', 10, 2)->nullable();

            // Cached balance of the stock ledger. Only StockService writes it.
            $table->integer('stock_quantity')->default(0);

            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
