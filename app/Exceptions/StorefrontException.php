<?php

namespace App\Exceptions;

use App\Enums\ApiCode;
use RuntimeException;

/**
 * A problem the customer can understand and act on — out of stock, expired coupon,
 * empty cart. The message is written for the shopper, not the log file, and is safe
 * to render straight into the UI.
 */
class StorefrontException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $status = 422,
        protected string $errorCode = ApiCode::GENERIC,
        protected array $context = []
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** Extra detail the UI can use, e.g. how many units are actually left. */
    public function context(): array
    {
        return $this->context;
    }

    public static function outOfStock(string $productName, int $available): self
    {
        $message = $available > 0
            ? "Only {$available} left in stock for \"{$productName}\". Please reduce the quantity."
            : "\"{$productName}\" just went out of stock. Please remove it from your cart to continue.";

        return new self($message, 422, ApiCode::OUT_OF_STOCK, [
            'product_name' => $productName,
            'available' => $available,
        ]);
    }

    public static function unavailable(string $productName): self
    {
        return new self(
            "\"{$productName}\" is no longer available. Please remove it from your cart to continue.",
            422,
            ApiCode::PRODUCT_UNAVAILABLE,
            ['product_name' => $productName]
        );
    }

    public static function emptyCart(): self
    {
        return new self(
            'Your cart is empty. Add a product before checking out.',
            422,
            ApiCode::CART_EMPTY
        );
    }
}
