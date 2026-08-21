<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g. "IDB Bhaban Flagship Showroom", "Multiplan Center Branch", "Uttara Branch", "Chattogram Agrabad Branch"
            $table->string('branch_type')->default('Showroom'); // Showroom, Service Center, Express Hub
            $table->string('city')->default('Dhaka');
            $table->string('address');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('opening_hours')->default('10:00 AM – 08:00 PM (Weekly Closed: Tuesday)');
            $table->text('map_embed_url')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
