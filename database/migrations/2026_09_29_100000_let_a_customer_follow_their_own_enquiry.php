<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An enquiry belongs to somebody, and a reply has a side.
 *
 * The contact inbox was the shop's alone: a message carried a name, an email
 * and a phone number but no account, and the answer went out by email. A
 * customer had no way to see what they had asked, whether it had been
 * answered, or to write back anywhere the shop would see it.
 *
 * user_id is what makes a thread theirs. Nullable because the contact form is
 * open to anyone, and a guest's enquiry has no account to hang on.
 *
 * from_customer tells the two sides of a thread apart. Until now every reply
 * was staff, so false is the right answer for all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_messages', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('contact_replies', function (Blueprint $table) {
            $table->boolean('from_customer')->default(false)->after('author_name');
        });

        /*
         * Enquiries already in the inbox are matched to accounts by address.
         *
         * Only where the address is an account's: it is what the customer
         * typed and what the answer was sent to, so it is the shop's own
         * record of who wrote in. Anything unmatched stays a guest's.
         */
        DB::table('users')->whereNotNull('email')->orderBy('id')->each(function ($user) {
            DB::table('contact_messages')
                ->whereNull('user_id')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])
                ->update(['user_id' => $user->id]);
        });
    }

    public function down(): void
    {
        Schema::table('contact_messages', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('contact_replies', function (Blueprint $table) {
            $table->dropColumn('from_customer');
        });
    }
};
