<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One name per shipping rate.
 *
 * The Settings form writes `shipping_inside_dhaka` / `shipping_outside_dhaka`
 * and the seeder wrote `delivery_charge_dhaka` / `delivery_charge_outside`.
 * Two names for each rate, and — until now — nothing read either of them, so
 * the disagreement was invisible.
 *
 * Anything a shop has already typed under the old names is carried across, so
 * a rate that was set (and silently ignored) starts being charged rather than
 * being dropped on the floor.
 */
return new class extends Migration
{
    private const MOVES = [
        'delivery_charge_dhaka' => 'shipping_inside_dhaka',
        'delivery_charge_outside' => 'shipping_outside_dhaka',
    ];

    public function up(): void
    {
        foreach (self::MOVES as $legacy => $current) {
            $value = DB::table('site_settings')->where('key', $legacy)->value('value');

            if ($value === null) {
                continue;
            }

            // Only when the shop has not already set the current one; what the
            // admin typed most recently under the name the form uses wins.
            $exists = DB::table('site_settings')->where('key', $current)->exists();

            if (! $exists) {
                DB::table('site_settings')->insert([
                    'key' => $current,
                    'value' => $value,
                    'group' => 'shipping',
                    'label' => $current === 'shipping_inside_dhaka'
                        ? 'Delivery Inside Dhaka'
                        : 'Delivery Outside Dhaka',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('site_settings')->where('key', $legacy)->delete();
        }
    }

    public function down(): void
    {
        foreach (self::MOVES as $legacy => $current) {
            $value = DB::table('site_settings')->where('key', $current)->value('value');

            if ($value !== null && ! DB::table('site_settings')->where('key', $legacy)->exists()) {
                DB::table('site_settings')->insert([
                    'key' => $legacy,
                    'value' => $value,
                    'group' => 'general',
                    'label' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
