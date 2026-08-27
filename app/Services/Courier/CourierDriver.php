<?php

namespace App\Services\Courier;

use App\Exceptions\CourierException;
use App\Models\Courier;
use App\Models\Order;

/**
 * How one carrier books a parcel.
 *
 * Each carrier's API is its own shape — different auth, different field names,
 * different idea of what an address is — so the differences live in a driver
 * rather than in the dispatch flow.
 */
interface CourierDriver
{
    /** Matches Courier::driver. */
    public function key(): string;

    public function label(): string;

    /**
     * What this carrier needs before it can book anything.
     *
     * Rendered as the credential form on the Couriers screen, so adding a
     * driver does not mean writing a form as well.
     *
     * @return array<int, array{name:string, label:string, secret?:bool, hint?:string}>
     */
    public function credentialFields(): array;

    /**
     * Hand the parcel over and come back with the consignment number.
     *
     * @throws CourierException when the carrier refuses or cannot be reached
     */
    public function createConsignment(Order $order, Courier $courier): Consignment;
}
