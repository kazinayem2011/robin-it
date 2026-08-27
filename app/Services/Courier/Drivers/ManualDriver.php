<?php

namespace App\Services\Courier\Drivers;

use App\Exceptions\CourierException;
use App\Models\Courier;
use App\Models\Order;
use App\Services\Courier\Consignment;
use App\Services\Courier\CourierDriver;

/**
 * No API: the number is whatever the admin typed.
 *
 * The right answer for a shop's own rider, and for carriers that publish no
 * API — Sundarban and SA Paribahan among the seeded ones. It is also the
 * fallback whenever an integrated carrier has no credentials saved, so
 * dispatching never becomes impossible because a key expired.
 */
class ManualDriver implements CourierDriver
{
    public function key(): string
    {
        return Courier::DRIVER_MANUAL;
    }

    public function label(): string
    {
        return 'Manual — enter the consignment number yourself';
    }

    public function credentialFields(): array
    {
        return [];
    }

    /**
     * Never called: dispatchOrder() uses the typed number directly when the
     * carrier cannot book. Present so the interface holds for every driver.
     */
    public function createConsignment(Order $order, Courier $courier): Consignment
    {
        throw CourierException::notConfigured($courier->name);
    }
}
