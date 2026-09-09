<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A category's old addresses, so changing one costs nothing.
 *
 * `/shop/{slug}` 404s on a slug the catalogue does not have, which is right —
 * before that a typo rendered an empty grid indistinguishable from a category
 * that had sold out, and five footer links had been pointing at slugs that
 * never existed. But it also means renaming a shelf silently breaks every link
 * to it: the shop's own menus repair themselves, and nothing else does — not a
 * customer's bookmark, not a search engine's index, not a link in an email
 * somebody sent last month.
 *
 * So a slug a category has used is kept, and the old address redirects to the
 * new one instead of failing. Which is also what makes the brand shelves
 * safe to rename in the migration that follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_slug_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            /*
             * Unique across the table: two categories cannot both claim to be
             * where an old address should lead, or the redirect has no answer.
             */
            $table->string('slug')->unique();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_slug_history');
    }
};
