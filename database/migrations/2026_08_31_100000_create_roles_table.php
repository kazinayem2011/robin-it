<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each job covers, decided by the shop rather than by the codebase.
 *
 * The five roles and their abilities were a constant, so a shop that wanted
 * its storekeepers to see the customer directory, or a role of its own for the
 * person who only answers the phone, needed a developer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // What is stored on users.role; it never moves once staff hold it.
            $table->string('key', 40)->unique();
            $table->string('label');
            $table->string('description', 300)->nullable();
            $table->json('abilities');

            /*
             * The owner and the customer. Owner is every ability by
             * definition, and a shop that could take abilities off it could
             * lock itself out of the screen that hands them back; customer is
             * not a staff role at all. Both may be renamed, neither may be
             * emptied or deleted.
             */
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
