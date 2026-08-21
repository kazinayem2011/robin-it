<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('badge')->nullable(); // e.g. "NEW ARRIVAL", "SAVE ৳15,000", "LIMITED TIME"
            $table->string('image_path');
            $table->string('link_url')->default('/shop');
            $table->string('button_text')->default('Shop Now');
            $table->enum('position', ['hero', 'promo_top', 'promo_side', 'spotlight'])->default('hero');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
