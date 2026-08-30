<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Specifications were a flat list of name/value pairs, which is fine for a
 * mouse and unreadable for a laptop: forty rows running from "Processor Brand"
 * to "Warranty" with nothing to break them up.
 *
 * Every shop this one is measured against groups them — Processor, Display,
 * Memory, Ports — so the group is stored per row rather than modelled as its
 * own table. It is a heading, not an entity: it has no attributes of its own,
 * and two products' "Display" sections have nothing to do with each other.
 *
 * Nullable, because a short list genuinely does not need headings, and because
 * every row that already exists predates this idea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_specifications', function (Blueprint $table) {
            $table->string('group')->nullable()->after('product_id');

            // Specs have a meaningful order — a spec sheet opens with the
            // processor, not the warranty — and insertion order is not it,
            // because editing one row would otherwise move it to the end.
            $table->unsignedSmallInteger('sort_order')->default(0)->after('value');

            // Every read is "all specs for this product, in order".
            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('product_specifications', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'sort_order']);
            $table->dropColumn(['group', 'sort_order']);
        });
    }
};
