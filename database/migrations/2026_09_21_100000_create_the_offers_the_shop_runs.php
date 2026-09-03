<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The campaigns the shop runs, as opposed to the discounts on its products.
 *
 * Two different things had been sharing one word. `/offers` rendered the
 * product listing with `onSaleOnly`, which is every product whose price is cut
 * — a fact derived from the catalogue, with nobody writing it. What a shop also
 * has is the offer it announces: "buy a desktop this month and get a gift",
 * running between two dates, at named outlets, with a page of its own
 * explaining the terms. Nothing in the schema could hold one.
 *
 * The discounted listing keeps working and moves to /discounts, which is what
 * it always was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            // The one line on the card, under the title.
            $table->string('excerpt')->nullable();
            // The terms, in full, on the offer's own page. Rich text, cleaned
            // on the way in by the model.
            $table->longText('content')->nullable();
            $table->string('image_path')->nullable();

            /*
             * When it runs. Both nullable: an offer with no end is a standing
             * one, and an offer with no start has always been on.
             */
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();

            // Where it applies — "All outlets", "Online only", a branch name.
            // Free text rather than a store_id: an offer often spans several.
            $table->string('availability')->nullable();

            // Where "See the products" goes: a category, a search, a campaign.
            $table->string('link_url')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // The storefront's only question: what is on right now?
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
