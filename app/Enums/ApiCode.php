<?php

namespace App\Enums;

/**
 * Machine-readable codes on every API response so the frontend can branch on the
 * cause rather than pattern-matching English message text.
 */
class ApiCode
{
    public const GENERIC = 'GENERIC';

    public const VALIDATION_ERROR = 'VALIDATION_ERROR';

    public const NOT_FOUND = 'NOT_FOUND';

    public const UNAUTHORIZED = 'UNAUTHORIZED';

    public const FORBIDDEN = 'FORBIDDEN';

    public const SERVER_ERROR = 'SERVER_ERROR';

    public const TOO_MANY_REQUESTS = 'TOO_MANY_REQUESTS';

    // Storefront-specific outcomes the UI reacts to.
    public const OUT_OF_STOCK = 'OUT_OF_STOCK';

    public const PRODUCT_UNAVAILABLE = 'PRODUCT_UNAVAILABLE';

    public const CART_EMPTY = 'CART_EMPTY';

    public const COUPON_INVALID = 'COUPON_INVALID';
}
