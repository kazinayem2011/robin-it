<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('spotlight_title')->nullable()->after('is_offer');
            $table->string('spotlight_subtitle')->nullable()->after('spotlight_title');
            $table->string('spotlight_image')->nullable()->after('spotlight_subtitle');
            $table->string('spotlight_link')->nullable()->after('spotlight_image');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('slug');
            $table->boolean('is_featured')->default(true)->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['spotlight_title', 'spotlight_subtitle', 'spotlight_image', 'spotlight_link']);
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'is_featured']);
        });
    }
};
