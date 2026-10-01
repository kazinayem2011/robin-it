<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A banner can sit beside the hero slider (hero_side), as StarTech's home
 * has two picture cards there.
 *
 * The placement was an enum of the four first thought of, so every new place
 * on the page meant a schema change — and 'popup', which the admin once
 * offered, was never in it. A short string now; the allowed places are the
 * request's rule (BannerRequest), where the rest of the banner's rules live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('position', 20)->default('hero')->change();
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->enum('position', ['hero', 'promo_top', 'promo_side', 'spotlight'])->default('hero')->change();
        });
    }
};
