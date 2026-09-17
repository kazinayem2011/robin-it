<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * An account the customer has not chosen a password for.
 *
 * Checkout makes an account for a guest who proves their mobile number, and
 * gave it a random password nobody knew. That made it impossible to tell such
 * an account from one with a real password, so checkout asked everybody with
 * an account for a password — including the customers who had never set one.
 *
 * Null says it plainly. Signing in with a password simply fails for it, the
 * checkout proves the number with a code instead, and "Forgot password" or the
 * profile sets one.
 *
 * Accounts that already exist keep what they have: which of them were made at
 * checkout is not recorded anywhere, so none is guessed at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Back to required: an account with none gets one nobody knows, which
        // is what these accounts had before.
        DB::table('users')->whereNull('password')->orderBy('id')->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'password' => Hash::make(Str::random(40)),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
