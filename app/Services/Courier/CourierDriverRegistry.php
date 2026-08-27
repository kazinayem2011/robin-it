<?php

namespace App\Services\Courier;

use App\Models\Courier;
use App\Services\Courier\Drivers\ManualDriver;
use App\Services\Courier\Drivers\PathaoDriver;
use App\Services\Courier\Drivers\RedxDriver;
use App\Services\Courier\Drivers\SteadfastDriver;

/**
 * Which driver books for which carrier.
 *
 * Adding a carrier's API means writing one driver and naming it here. Nothing
 * in the dispatch flow changes, and neither does the credential form — that is
 * built from whatever the driver says it needs.
 */
class CourierDriverRegistry
{
    /** @var array<string, class-string<CourierDriver>> */
    private const DRIVERS = [
        Courier::DRIVER_MANUAL => ManualDriver::class,
        'pathao' => PathaoDriver::class,
        'steadfast' => SteadfastDriver::class,
        'redx' => RedxDriver::class,
    ];

    /** @var array<string, CourierDriver> */
    private array $resolved = [];

    public function for(Courier $courier): CourierDriver
    {
        return $this->get($courier->driver ?? Courier::DRIVER_MANUAL);
    }

    public function get(string $key): CourierDriver
    {
        // An unknown driver — a row edited by hand, or a driver removed in a
        // later version — falls back to manual rather than failing dispatch
        // outright. The parcel still goes out.
        $class = self::DRIVERS[$key] ?? ManualDriver::class;

        return $this->resolved[$key] ??= app($class);
    }

    /**
     * Every driver, for the courier form's dropdown and its credential fields.
     *
     * @return array<int, array{key:string, label:string, fields:array}>
     */
    public function all(): array
    {
        return collect(self::DRIVERS)
            ->map(fn ($class, $key) => $this->get($key))
            ->map(fn (CourierDriver $d) => [
                'key' => $d->key(),
                'label' => $d->label(),
                'fields' => $d->credentialFields(),
            ])
            ->values()
            ->all();
    }

    public function keys(): array
    {
        return array_keys(self::DRIVERS);
    }
}
