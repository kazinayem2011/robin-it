<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Warranty terms are a list, not a line.
 *
 * The column was varchar(255), which is one sentence — "2 Years warranty
 * (Battery & Adapter 1 Year)" and little else. A real warranty has clauses:
 * what is covered, what is not, what the customer has to keep, where to bring
 * it. Four of those exceed 255 characters, and the field was a single-line
 * input that could not hold a newline anyway.
 *
 * `text` rather than a larger varchar: the length is set by what a shop wants
 * to say, and picking a number here only moves the ceiling somewhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('warranty_text')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Anything already written past 255 characters would be cut here, so
         * it is truncated deliberately rather than left to the database to
         * refuse the change or silently trim it.
         */
        DB::table('products')
            ->whereNotNull('warranty_text')
            ->update(['warranty_text' => DB::raw('LEFT(warranty_text, 255)')]);

        Schema::table('products', function (Blueprint $table) {
            $table->string('warranty_text', 255)->nullable()->change();
        });
    }
};
