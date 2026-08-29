<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a customer wants to hear from the shop about anything but their order.
 *
 * There was no such flag, so a broadcast would have gone to everybody who had
 * ever bought anything with no way for them to stop it. An order confirmation
 * is something a customer asked for; a sale announcement is not.
 *
 * Default true: somebody who gives a shop their number to receive a delivery
 * expects to hear from that shop, and a list nobody is on is a feature nobody
 * uses. What matters is that saying no works, and it does — the unsubscribe
 * link already in every email now switches this off as well, matched on the
 * email address, so leaving the list once leaves it everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('accepts_marketing')->default(true)->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('accepts_marketing');
        });
    }
};
