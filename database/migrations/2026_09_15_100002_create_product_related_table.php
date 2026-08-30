<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand-picked "Similar Product" suggestions.
 *
 * The fallback — other products in the same category — is what the storefront
 * does today and is usually fine. It is wrong exactly where it matters: the
 * accessory that fits this laptop, the cooler that clears this case, the
 * cheaper model a customer should be shown before they leave. Those are
 * merchandising decisions and cannot be derived.
 *
 * Deliberately not symmetric. "Buy this cable with this monitor" is a sensible
 * suggestion; "buy this monitor with this cable" is not, and forcing both would
 * fill every cable's page with monitors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_related', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['product_id', 'related_product_id']);
            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_related');
    }
};
