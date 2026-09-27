<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders no longer have a draft stage.
 *
 * "Send to the supplier" only changed the status word, and Receive stayed
 * hidden until someone remembered to click it. An order is now open from the
 * moment it is saved, so any draft left over is opened as it would have been.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('purchase_orders')
            ->where('status', 'draft')
            ->update(['status' => 'sent', 'sent_at' => DB::raw('COALESCE(sent_at, created_at)')]);
    }

    public function down(): void
    {
        // Nothing to undo: a draft and an open order hold the same lines.
    }
};
