<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ShippingRates;
use Illuminate\Support\Facades\DB;

class CartService
{
    /** Sanity cap so a single line item can't be used to inflate an order. */
    public const MAX_QUANTITY_PER_ITEM = 20;

    /**
     * Get or create the cart for an authenticated user or guest session.
     */
    public function getOrCreateCart(?int $userId, ?string $sessionId): Cart
    {
        return Cart::firstOrCreate($this->ownerAttributes($userId, $sessionId));
    }

    /**
     * Read-only lookup. Browsing the cart page shouldn't write a row for every
     * visitor that never adds anything.
     */
    public function findCart(?int $userId, ?string $sessionId): ?Cart
    {
        return Cart::where($this->ownerAttributes($userId, $sessionId))->first();
    }

    /**
     * Get the cart along with loaded relationships.
     */
    public function getCartWithItems(?int $userId, ?string $sessionId): Cart
    {
        $cart = $this->findCart($userId, $sessionId) ?: new Cart($this->ownerAttributes($userId, $sessionId));

        if ($cart->exists) {
            $cart->load(['items.product.images', 'items.product.brand', 'items.variant']);
        } else {
            $cart->setRelation('items', collect());
        }

        return $cart;
    }

    /**
     * Add an item to the cart or increment quantity if already present.
     *
     * @throws StorefrontException when the product is unavailable or short on stock
     */
    public function addItem(Cart $cart, int $productId, int $quantity = 1, ?int $variantId = null): CartItem
    {
        $product = Product::find($productId);

        if (! $product || ! $product->is_active) {
            throw StorefrontException::unavailable($product->name ?? 'This product');
        }

        $variant = $this->resolveVariant($product, $variantId);

        // Two options of the same product are two lines, not one, because each
        // draws from its own shelf.
        $cartItem = CartItem::firstOrNew([
            'cart_id' => $cart->id,
            'product_id' => $productId,
            'product_variant_id' => $variant?->id,
        ]);

        $requested = ($cartItem->exists ? $cartItem->quantity : 0) + max(1, $quantity);

        $this->assertStockCovers($product, $requested, $variant);

        $cartItem->quantity = min($requested, self::MAX_QUANTITY_PER_ITEM);
        $cartItem->save();

        return $cartItem;
    }

    /**
     * A variant product can only be bought by the option, never as a whole, and
     * an option from a different product is never acceptable.
     *
     * @throws StorefrontException
     */
    private function resolveVariant(Product $product, ?int $variantId): ?ProductVariant
    {
        if (! $product->has_variants) {
            // Ignore a stray option id on a single product rather than failing:
            // the shopper's intent is unambiguous.
            return null;
        }

        if (! $variantId) {
            throw new StorefrontException(
                "Choose an option for {$product->name} before adding it to your cart.",
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $variant = ProductVariant::where('product_id', $product->id)
            ->where('is_active', true)
            ->find($variantId);

        if (! $variant) {
            throw new StorefrontException(
                'That option is no longer available for this product.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        return $variant;
    }

    /**
     * Update the quantity of a specific cart item.
     *
     * @throws StorefrontException when the requested quantity exceeds stock
     */
    public function updateItemQuantity(Cart $cart, int $itemId, int $quantity): CartItem
    {
        $cartItem = CartItem::where('cart_id', $cart->id)
            ->where('id', $itemId)
            ->firstOrFail();

        $product = $cartItem->product;

        if (! $product || ! $product->is_active) {
            throw StorefrontException::unavailable($product->name ?? 'This product');
        }

        $variant = $cartItem->variant;

        if ($cartItem->product_variant_id && (! $variant || ! $variant->is_active)) {
            throw StorefrontException::unavailable($cartItem->displayName());
        }

        $requested = max(1, min($quantity, self::MAX_QUANTITY_PER_ITEM));

        $this->assertStockCovers($product, $requested, $variant);

        $cartItem->update(['quantity' => $requested]);

        return $cartItem;
    }

    /**
     * Remove an item from the cart.
     */
    public function removeItem(Cart $cart, int $itemId): bool
    {
        return (bool) CartItem::where('cart_id', $cart->id)
            ->where('id', $itemId)
            ->delete();
    }

    /**
     * Move a guest's session cart onto their account at login, so nothing they
     * picked out before signing in disappears.
     */
    public function mergeGuestCart(int $userId, ?string $sessionId): void
    {
        if (! $sessionId) {
            return;
        }

        $guestCart = Cart::where('session_id', $sessionId)->whereNull('user_id')->first();

        if (! $guestCart) {
            return;
        }

        DB::transaction(function () use ($guestCart, $userId) {
            $userCart = Cart::firstOrCreate(['user_id' => $userId]);

            if ($userCart->id === $guestCart->id) {
                return;
            }

            $guestCart->load('items.product', 'items.variant');

            foreach ($guestCart->items as $guestItem) {
                // Match on the option too, so the 16GB and 32GB lines the
                // shopper picked as a guest do not collapse into one.
                $existing = CartItem::firstOrNew([
                    'cart_id' => $userCart->id,
                    'product_id' => $guestItem->product_id,
                    'product_variant_id' => $guestItem->product_variant_id,
                ]);

                $combined = ($existing->exists ? $existing->quantity : 0) + $guestItem->quantity;

                // Merging must never fail the login — clamp instead of throwing.
                $available = $guestItem->variant?->stock_quantity
                    ?? $guestItem->product?->stock_quantity
                    ?? $combined;

                $existing->quantity = max(1, min($combined, self::MAX_QUANTITY_PER_ITEM, $available));
                $existing->save();
            }

            $guestCart->items()->delete();
            $guestCart->delete();
        });
    }

    /**
     * Calculate subtotal, shipping, discount, and grand total for a cart.
     *
     * @param  float  $discount  coupon discount already validated server-side
     * @param  string|null  $city  where it is going, when that is known yet.
     *                             The cart page has no address, so it quotes
     *                             the inside-Dhaka rate; checkout knows and
     *                             charges accordingly.
     */
    public function calculateTotals(Cart $cart, float $discount = 0.0, ?string $city = null): array
    {
        $cart->loadMissing('items.product', 'items.variant');

        $subtotal = 0.0;
        $totalItems = 0;

        foreach ($cart->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            // Price comes from whichever level owns it — the chosen option
            // when there is one, otherwise the product.
            $subtotal += $item->unitPrice() * $item->quantity;
            $totalItems += $item->quantity;
        }

        $subtotal = round($subtotal, 2);
        $discount = round(min(max($discount, 0.0), $subtotal), 2);
        // Measured against the goods total before the coupon: a promo code
        // should not cost the customer their free delivery.
        $shipping = ShippingRates::feeFor($city, $subtotal);

        return [
            'subtotal' => $subtotal,
            'shipping_fee' => $shipping,
            'discount' => $discount,
            'total' => round(max(0.0, $subtotal - $discount + $shipping), 2),
            'total_items' => $totalItems,
        ];
    }

    /**
     * Clear all items and remove the cart.
     */
    public function clearCart(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->delete();
    }

    /**
     * Cart lines whose product went inactive or short on stock since it was added.
     * The cart page uses this to warn the customer before they reach checkout.
     *
     * @return array<int, array{item_id:int, product_name:string, requested:int, available:int, reason:string}>
     */
    public function findUnavailableItems(Cart $cart): array
    {
        $cart->loadMissing('items.product', 'items.variant');

        $issues = [];

        foreach ($cart->items as $item) {
            $product = $item->product;
            $variant = $item->variant;

            $optionGone = $item->product_variant_id && (! $variant || ! $variant->is_active);

            if (! $product || ! $product->is_active || $optionGone) {
                $issues[] = [
                    'item_id' => $item->id,
                    'product_name' => $product ? $item->displayName() : 'Removed product',
                    'requested' => $item->quantity,
                    'available' => 0,
                    'reason' => 'unavailable',
                ];

                continue;
            }

            // Stock lives on the option when the product has them.
            $available = (int) ($variant?->stock_quantity ?? $product->stock_quantity);

            // A pre-ordered line is not an unavailable one; the customer has
            // already been told it ships when the delivery lands.
            if ($available < $item->quantity
                && ! $product->allowsBalance($available - $item->quantity)) {
                $issues[] = [
                    'item_id' => $item->id,
                    'product_name' => $item->displayName(),
                    'requested' => $item->quantity,
                    'available' => max(0, $available),
                    'reason' => 'insufficient_stock',
                ];
            }
        }

        return $issues;
    }

    /**
     * Owner key for a cart: the account when signed in, otherwise the session.
     *
     * @return array<string, mixed>
     */
    private function ownerAttributes(?int $userId, ?string $sessionId): array
    {
        return $userId
            ? ['user_id' => $userId]
            : ['session_id' => $sessionId];
    }

    /**
     * @throws StorefrontException
     */
    private function assertStockCovers(Product $product, int $quantity, ?ProductVariant $variant = null): void
    {
        if (! $product->is_active) {
            throw StorefrontException::unavailable($product->name);
        }

        /*
         * The same rule the ledger applies, asked one step earlier: what would
         * the balance be, and is the product allowed to sit there? On a
         * pre-order product that permits it, this lets the line through and the
         * shelf goes negative at checkout, meaning units owed.
         */
        $onHand = (int) ($variant?->stock_quantity ?? $product->stock_quantity);

        if ($product->allowsBalance($onHand - $quantity)) {
            return;
        }

        throw StorefrontException::outOfStock(
            $variant ? "{$product->name} ({$variant->name})" : $product->name,
            max(0, $onHand)
        );
    }
}
