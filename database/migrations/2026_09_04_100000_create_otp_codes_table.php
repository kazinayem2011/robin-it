<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes sent to a mobile number.
 *
 * Two holes this closes. A sign-up asked for a mobile number and never checked
 * it, so an account could be opened on somebody else's number — and since that
 * number is what the order confirmation, the dispatch note and the delivery
 * rider all go to, the mistake surfaces as a stranger receiving a parcel.
 *
 * The second is worse for the customer: a password reset went by email only.
 * Plenty of people here sign up with an address they never open, and once the
 * password is gone so is the account, along with its order history and its
 * warranty records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();

            // Always normalised before it is stored, so the same number typed
            // three ways is one row rather than three attempts at the cap.
            $table->string('phone', 20);

            // What the code lets you do. A code sent to confirm a sign-up must
            // not be usable to reset a password: the two are asked for in
            // different places and one is far more valuable than the other.
            $table->string('purpose', 32);

            // Hashed. The plain code exists only in the text message — a
            // database that leaks should not hand over accounts as well.
            $table->string('code_hash');

            $table->timestamp('expires_at');

            // Guessing is cheap against six digits, so the count is kept here
            // rather than in a cache a restart would clear.
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('used_at')->nullable();

            // For working out afterwards where a burst of requests came from.
            $table->string('ip', 45)->nullable();

            $table->timestamps();

            // The lookup every check makes: the live code for this number and
            // this purpose, newest first.
            $table->index(['phone', 'purpose', 'used_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
