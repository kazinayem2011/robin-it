<?php

namespace App\Exceptions;

use App\Enums\ApiCode;

/**
 * A courier refused the parcel, or could not be reached.
 *
 * Separate from StorefrontException because the audience is the admin, not the
 * shopper: the message is what the carrier said, so whoever is dispatching can
 * act on it — a missing phone number, an address the carrier will not serve, an
 * expired key.
 */
class CourierException extends \RuntimeException
{
    public function __construct(
        string $message,
        protected string $courierName = '',
        protected array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function refused(string $courier, string $reason, array $context = []): self
    {
        return new self("{$courier} would not accept this parcel: {$reason}", $courier, $context);
    }

    public static function unreachable(string $courier, string $reason): self
    {
        return new self(
            "Could not reach {$courier} ({$reason}). The order has not been marked shipped — "
                .'try again, or dispatch it manually with a number from their panel.',
            $courier
        );
    }

    public static function notConfigured(string $courier): self
    {
        return new self(
            "{$courier} has no API credentials saved, so it cannot book the parcel. "
                .'Add them under Couriers, or enter a consignment number by hand.',
            $courier
        );
    }

    public function courierName(): string
    {
        return $this->courierName;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function errorCode(): string
    {
        return ApiCode::GENERIC;
    }
}
