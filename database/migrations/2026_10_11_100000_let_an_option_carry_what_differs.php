<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one option has that its siblings do not.
 *
 * A laptop sold as Core i5 / 512GB, Core i5 / 1TB and Core i7 / 512GB shares
 * fifty spec rows and differs in half a dozen — processor, memory layout,
 * webcam, weight. Picking an option changed only the price, so the page kept
 * describing whichever build the text was written for. An option now carries
 * its own key features and the spec rows that are different on it; the page
 * swaps them in when it is chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->text('key_features')->nullable()->after('options');
            // [{group, name, value}] — only the rows that differ.
            $table->json('specifications')->nullable()->after('key_features');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['key_features', 'specifications']);
        });
    }
};
