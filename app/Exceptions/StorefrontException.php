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

    /**
     * Extra detail the UI can use, e.g. which product it was. Never a stock
     * figure: this is sent to the customer's browser.
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * More than the shop can supply.
     *
     * The shop's rule is that a customer is never told how many units there
     * are, so this says "that many" and not a number — it used to read "Only 3
     * left in stock", and carried the figure in its context too. $available
     * only decides between "reduce it" and "remove it"; with none at all the
     * product is Sold Out on every page anyway, so saying so gives nothing away.
     */
    public static function outOfStock(string $productName, int $available): self
    {
        $message = $available > 0
            ? "We can't supply that many of \"{$productName}\" right now. Please reduce the quantity."
            : "\"{$productName}\" just went out of stock. Please remove it from your cart to continue.";

        return new self($message, 422, ApiCode::OUT_OF_STOCK, [
            'product_name' => $productName,
        ]);
    }

    /**
     * Past a pre-order product's limit.
     *
     * No number, for the same reason as outOfStock(): the limit less what is
     * owed is a stock figure. Same code as out-of-stock, so a client handling
     * that handles this.
     */
    public static function preorderLimit(string $productName, int $ceiling): self
    {
        $message = $ceiling > 0
            ? "We can't take that many pre-orders for \"{$productName}\" right now. Please reduce the quantity."
            : "\"{$productName}\" has reached its pre-order limit. Please remove it from your cart to continue.";

        return new self($message, 422, ApiCode::OUT_OF_STOCK, [
            'product_name' => $productName,
        ]);
    }

    /**
     * Staff moving stock that is not there — an adjustment, a transfer, a
     * write-off. Staff see the stock anyway, so this keeps the number and the
     * wording the stock screens always had. Never thrown on a customer's path.
     */
    public static function notEnoughOnHand(string $productName, int $available): self
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
