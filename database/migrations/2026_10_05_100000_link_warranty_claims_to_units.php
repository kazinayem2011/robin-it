<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A claim knows which of the shop's units it is about, and what replaced it.
 *
 * A claim was a typed serial and nothing more: staff could not tell a unit the
 * shop sold last month from one it never sold, and a replacement moved no
 * stock. Existing claims are linked where their serial matches a unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            $table->foreignId('product_serial_id')->nullable()->after('serial_number')
                ->constrained('product_serials')->nullOnDelete();
            $table->foreignId('replacement_serial_id')->nullable()->after('product_serial_id')
                ->constrained('product_serials')->nullOnDelete();
        });

        foreach (DB::table('warranty_claims')->get(['id', 'serial_number']) as $claim) {
            $serial = strtoupper(preg_replace('/\s+/', '', (string) $claim->serial_number));
            $unit = DB::table('product_serials')->where('serial', $serial)->value('id');

            if ($unit) {
                DB::table('warranty_claims')->where('id', $claim->id)->update(['product_serial_id' => $unit]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replacement_serial_id');
            $table->dropConstrainedForeignId('product_serial_id');
        });
    }
};
