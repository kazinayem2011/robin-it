<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An enquiry need not carry an email address.
 *
 * The contact form demanded one from everybody, including the customers who
 * registered with a mobile number and have none. They had two ways out and
 * both were wrong: invent an address the shop cannot reach them at, or type
 * somebody else's — which is exactly how an answer ends up in a stranger's
 * inbox.
 *
 * A signed-in customer needs no address at all: the answer appears in their
 * own messages and rings their bell. A guest is asked for an address or a
 * mobile number, so there is always some way to answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_messages', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contact_messages', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
