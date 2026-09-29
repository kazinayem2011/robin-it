<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a claim was ready to collect, and when it was finished.
 *
 * The warranty report measures how long a repair takes, and nothing recorded
 * either moment. Claims already past them take their last-updated time — the
 * closest thing there is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            $table->timestamp('ready_at')->nullable()->after('status');
            $table->timestamp('closed_at')->nullable()->after('ready_at');
        });

        DB::table('warranty_claims')
            ->whereIn('status', ['ready_for_pickup', 'completed'])
            ->update(['ready_at' => DB::raw('updated_at')]);

        DB::table('warranty_claims')
            ->whereIn('status', ['completed', 'rejected'])
            ->update(['closed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            $table->dropColumn(['ready_at', 'closed_at']);
        });
    }
};
