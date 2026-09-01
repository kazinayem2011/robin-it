<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer may have a mobile number instead of an email address.
 *
 * Signing in has always taken either — LoginRequest reads the field, decides
 * whether it looks like an address or a Bangladeshi mobile, and authenticates
 * on whichever it is. Signing up demanded both, and the column was NOT NULL,
 * so the one identifier most customers here actually have was never enough on
 * its own.
 *
 * Nothing is dropped: the unique index stays, and MySQL allows any number of
 * NULLs in one, so two accounts without an address do not collide while two
 * with the same address still do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Rows written since this ran may have no address, and the column
         * cannot be made NOT NULL while they are there. A placeholder would be
         * worse than failing: it would look like an address, and the shop
         * would try to write to it. So they are named and the migration stops.
         */
        $without = DB::table('users')->whereNull('email')->count();

        if ($without > 0) {
            throw new RuntimeException(
                "Cannot restore NOT NULL on users.email: {$without} account(s) have only a mobile number. "
                .'Give them an address or remove them first.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
