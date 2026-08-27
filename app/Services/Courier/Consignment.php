<?php

namespace App\Services\Courier;

/**
 * What a carrier gives back when it accepts a parcel.
 */
class Consignment
{
    public function __construct(
        /** The number the customer chases the parcel with. */
        public readonly string $trackingNumber,
        /** Everything the carrier said, kept for support and for debugging. */
        public readonly array $raw = [],
    ) {}
}
