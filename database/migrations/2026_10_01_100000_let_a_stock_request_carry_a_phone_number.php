<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Notify me" by text as well as by email.
 *
 * The list took an email address and nothing else, so a customer whose account
 * is a mobile number — the usual kind here — could not join it, and a guest
 * without an address was turned away. A request now carries one or the other:
 * the email, or the mobile it is texted to. One outstanding request per number
 * per item, as for an address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_notifications', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone', 20)->nullable()->after('email');
            $table->unique(['product_id', 'product_variant_id', 'phone'], 'stock_notify_phone_unique');
        });

        /*
         * The text's wording, so the shop can reword it beside the others.
         * Only where the others are already there: an empty table means the
         * seeder has not run, and it brings this one with the rest. Without
         * a row the built-in wording is sent, so nothing depends on it.
         */
        if (Schema::hasTable('sms_templates') && DB::table('sms_templates')->exists()) {
            DB::table('sms_templates')->insertOrIgnore([
                'key' => 'back_in_stock',
                'name' => 'Back in stock',
                'group' => 'Catalogue',
                'hint' => 'For someone who pressed Notify me and left a mobile number. Adding {product_url} usually makes it three parts.',
                'body' => '({shop_name}) {product_name} আবার স্টকে এসেছে। এখনই অর্ডার করুন।',
                'variables' => json_encode(['shop_name', 'product_name', 'product_url']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sms_templates')) {
            DB::table('sms_templates')->where('key', 'back_in_stock')->delete();
        }

        // Requests by phone have no address to fall back on, so they go too.
        DB::table('stock_notifications')->whereNull('email')->delete();

        Schema::table('stock_notifications', function (Blueprint $table) {
            $table->dropUnique('stock_notify_phone_unique');
            $table->dropColumn('phone');
            $table->string('email')->nullable(false)->change();
        });
    }
};
