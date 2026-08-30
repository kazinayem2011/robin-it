<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Describe what each photo actually shows.
 *
 * Every gallery image currently reuses the product name as its alt text, so a
 * screen reader hears the same forty-character string five times and learns
 * nothing about any of the pictures. The shop being modelled writes a real
 * sentence per shot:
 *
 *     "Angled rear view … showing slim lid and hinge design"
 *     "Front open view … showing display and backlit keyboard"
 *     "Bottom view … showing vent layout and base design"
 *
 * That is the difference between a gallery a blind customer can use and one
 * they cannot, and it is also what puts the images into Google Image search.
 *
 * Nullable, and the product name stays the fallback, so nothing regresses for
 * the images already uploaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('alt_text', 255)->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('alt_text');
        });
    }
};
