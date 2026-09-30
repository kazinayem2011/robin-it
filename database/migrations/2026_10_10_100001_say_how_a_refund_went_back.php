<?php

use App\Support\MessageKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The refund text told everyone "it may take a few days to reach the bank",
 * cash refunds included. The wording now carries {refund_how}, which says how
 * the money went back.
 *
 * Only where the shop never reworded it: a row still holding the seeded text
 * is replaced; one the shop wrote itself is left alone.
 */
return new class extends Migration
{
    private const OLD = '({shop_name}) অর্ডার {order_number}-এর Tk {amount} রিফান্ড হয়েছে। ব্যাংকে আসতে কয়েক দিন লাগতে পারে।';

    private const NEW = '({shop_name}) অর্ডার {order_number}-এর Tk {amount} {refund_how}';

    public function up(): void
    {
        if (! Schema::hasTable('sms_templates')) {
            return;
        }

        DB::table('sms_templates')->where('key', 'refund')->where('body', self::OLD)->update([
            'body' => self::NEW,
            'hint' => 'Without this the customer chases the money. {refund_how} says how it went back — cash in hand, sent to bKash/Nagad/Rocket, or to the bank (which takes days).',
            'updated_at' => now(),
        ]);

        // Offered as a placeholder either way, so a shop's own wording can use it.
        DB::table('sms_templates')->where('key', 'refund')->update([
            'variables' => json_encode(MessageKeys::sms('refund')),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('sms_templates')) {
            DB::table('sms_templates')->where('key', 'refund')->where('body', self::NEW)->update(['body' => self::OLD]);
        }
    }
};
