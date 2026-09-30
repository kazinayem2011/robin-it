<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a refund gave back the delivery charge as well as the goods.
 *
 * A return took the goods off the order and left the delivery charge owing —
 * right when the customer changed their mind, wrong when the shop sent the
 * wrong thing. The refund form let the whole amount go back either way, and
 * an order returned and refunded in full then read "৳70 owed". The shop now
 * says which it is, as other shops do: refunded for the shop's fault, kept for
 * a change of mind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->boolean('includes_delivery')->default(false)->after('reason');
        });

        /*
         * Orders that already went all the way back — returned, and every taka
         * of the total refunded — clearly gave the delivery charge back too.
         * Marked on their latest refund so they stop showing it as owed.
         */
        $orders = DB::table('orders')
            ->where('status', 'returned')
            ->where('shipping_fee', '>', 0)
            ->get(['id', 'total']);

        foreach ($orders as $order) {
            $refunded = (float) DB::table('refunds')->where('order_id', $order->id)->sum('amount');

            if ($refunded + 0.005 >= (float) $order->total) {
                $latest = DB::table('refunds')->where('order_id', $order->id)->orderByDesc('id')->value('id');
                DB::table('refunds')->where('id', $latest)->update(['includes_delivery' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn('includes_delivery');
        });
    }
};
