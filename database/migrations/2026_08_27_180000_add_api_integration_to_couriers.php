<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a courier book the parcel itself instead of the admin typing a number.
 *
 * Recording a consignment number by hand means someone has already created the
 * booking in the carrier's own panel and copied the number across — two places
 * to get it wrong, and a customer who is told nothing until that happens.
 *
 * With credentials, dispatching calls the carrier, the carrier issues the
 * consignment number, and the tracking link works immediately.
 *
 * `driver` is what decides: `manual` is the default and keeps the old
 * behaviour, which is right for a shop's own rider and for carriers with no
 * API. Credentials are encrypted — they are live keys to a paid account.
 */
return new class extends Migration
{
    /** Carriers whose API this ships a driver for. */
    private const DRIVERS = [
        'pathao' => 'pathao',
        'steadfast' => 'steadfast',
        'redx' => 'redx',
    ];

    public function up(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->string('driver', 40)->default('manual')->after('slug');
            // Encrypted at rest: these are keys to a live merchant account.
            $table->text('credentials')->nullable()->after('driver');
            // Every one of these carriers has a separate sandbox to try first.
            $table->boolean('is_sandbox')->default(false)->after('credentials');
        });

        // Point the seeded carriers at their drivers. They stay on manual in
        // practice until someone enters credentials — the driver is only used
        // when there are keys for it.
        foreach (self::DRIVERS as $slug => $driver) {
            DB::table('couriers')->where('slug', $slug)->update(['driver' => $driver]);
        }
    }

    public function down(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->dropColumn(['driver', 'credentials', 'is_sandbox']);
        });
    }
};
