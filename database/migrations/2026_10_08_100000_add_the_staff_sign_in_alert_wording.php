<?php

use App\Support\MessageKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's "staff sign-in changed" text, in Bengali and editable beside the
 * others. Only where the others are already there: an empty table means the
 * seeder has not run, and it brings this one with the rest. Without a row the
 * built-in wording is sent, so nothing depends on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sms_templates') && DB::table('sms_templates')->exists()) {
            DB::table('sms_templates')->insertOrIgnore([
                'key' => 'staff_signin_changed',
                'name' => 'Staff sign-in changed (to the owner)',
                'group' => 'Account',
                'hint' => 'Texted to the owner when a staff or admin password, email or mobile changes. Never sent to customers. Keep it to two parts; with a long name {changed_by} is dropped to fit.',
                'body' => '({shop_name}) লগইন বদলেছে: {staff_name}-এর {changed}, {changed_at}, করেছেন {changed_by}। অজানা হলে Staff পেজ দেখুন।',
                'variables' => json_encode(MessageKeys::sms('staff_signin_changed')),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sms_templates')) {
            DB::table('sms_templates')->where('key', 'staff_signin_changed')->delete();
        }
    }
};
