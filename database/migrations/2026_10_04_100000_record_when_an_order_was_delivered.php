<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the goods reached the customer.
 *
 * Reports count a sale once it is delivered, on the day it was delivered.
 * Nothing recorded that day, so orders already delivered take the last time
 * they changed — the closest thing to it there is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('dispatched_at')->index();
        });

        DB::table('orders')
            ->whereIn('status', ['delivered', 'returned'])
            ->update(['delivered_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['delivered_at']);
            $table->dropColumn('delivered_at');
        });
    }
};
