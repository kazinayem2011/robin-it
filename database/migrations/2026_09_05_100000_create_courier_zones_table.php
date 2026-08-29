<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of a courier's delivery areas a customer's address belongs to.
 *
 * Pathao and RedX book against numeric ids from their own area lists, not
 * against a written address. Nothing mapped them, so every parcel went out on
 * the one default zone saved with the credentials — right for Dhaka, wrong for
 * everywhere else, and wrong in a way that shows up as a rider in the wrong
 * district rather than as an error anybody sees.
 *
 * Checkout collects city and zone as free text, so the match is on what the
 * customer actually typed, lowercased and trimmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();

            // As typed at checkout, normalised. "Dhaka", "dhaka " and "DHAKA"
            // are one row, because they are one place.
            $table->string('city', 100);

            // The finer division, where the shop has bothered to map one. Null
            // means "anywhere in this city we have not named".
            $table->string('zone', 100)->nullable();

            // What the courier calls it. Which of these matter depends on the
            // courier: Pathao wants city and zone, RedX wants an area.
            $table->string('city_id', 32)->nullable();
            $table->string('zone_id', 32)->nullable();
            $table->string('area_id', 32)->nullable();

            $table->timestamps();

            // One mapping per place per courier. A second row for the same
            // place is a contradiction, not an alternative.
            $table->unique(['courier_id', 'city', 'zone']);
            $table->index(['courier_id', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_zones');
    }
};
