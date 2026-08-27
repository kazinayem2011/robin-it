<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Helpers\PhoneHelper;
use App\Mail\OrderConfirmationMail;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        protected CartService $cartService,
        protected StockService $stock
    ) {}

    /**
     * Process checkout: validate the cart against live stock, create the order and
     * its lines, reserve stock atomically, redeem any coupon, then clear the cart.
     *
     * @throws StorefrontException when the cart is empty, a product went away,
     *                             or stock ran out between browsing and paying
     */
    public function placeOrder(
        Cart $cart,
        array $addressData,
        ?int $userId = null,
        ?string $sessionId = null,
        ?Coupon $coupon = null
    ): Order {
        $cart->load('items.product', 'items.variant');

        if ($cart->items->isEmpty()) {
            throw StorefrontException::emptyCart();
        }

        // Fail fast with a clear message before opening a transaction.
        $this->assertCartIsPurchasable($cart);

        $order = DB::transaction(function () use ($cart, $addressData, $userId, $sessionId, $coupon) {
            $discount = 0.0;

            if ($coupon) {
                // Recomputed server-side — never trust a discount posted by the
                // browser — and only against the lines the coupon covers.
                $discount = $coupon->discountFor($coupon->eligibleSubtotal($cart));

                // The customer is passed so the per-customer cap is re-counted
                // here, under a row lock, rather than only in the controller
                // before the transaction opened — where two checkouts fired at
                // once both read "not used yet" and both went through.
                if (! $coupon->redeem($userId)) {
                    throw new StorefrontException(
                        'This coupon has just reached its usage limit. Please remove it and try again.',
                        422,
                        ApiCode::COUPON_INVALID
                    );
                }
            }

            // The delivery city decides the rate; the cart page quoted the
            // inside-Dhaka one because it had no address to go on yet.
            $totals = $this->cartService->calculateTotals($cart, $discount, $addressData['city'] ?? null);

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $userId,
                'session_id' => $userId ? null : $sessionId,
                'subtotal' => $totals['subtotal'],
                'shipping_fee' => $totals['shipping_fee'],
                'discount' => $totals['discount'],
                // Frozen with the order: rates change and shops switch between
                // inclusive and exclusive pricing, and an old invoice still has
                // to reconcile. `vat_inclusive` records what the amount means —
                // taken out of the price paid, or added on top of it.
                'vat_amount' => $totals['vat'],
                'vat_rate' => $totals['vat_rate'],
                'vat_inclusive' => $totals['vat_inclusive'],
                'coupon_code' => $coupon?->code,
                // The terms as well as the code. The amount above was always
                // right, but with only a code recorded nobody could say why —
                // edit the coupon later and the order became unexplainable.
                'coupon_discount_type' => $coupon?->discount_type,
                'coupon_discount_value' => $coupon?->discount_value,
                'total' => $totals['total'],
                'status' => 'pending',
                'payment_method' => $this->normalisePaymentMethod($addressData['payment_method'] ?? null),
                'payment_status' => 'unpaid',
                'shipping_address' => [
                    'name' => $addressData['name'],
                    'phone' => PhoneHelper::normalizeBdPhone($addressData['phone']) ?? $addressData['phone'],
                    'street_address' => $addressData['street_address'],
                    'city' => $addressData['city'],
                    'zone' => $addressData['zone'] ?? null,
                ],
            ]);

            foreach ($cart->items as $item) {
                $product = $item->product;
                if (! $product) {
                    continue;
                }

                $variant = $item->variant;
                $effectivePrice = $item->unitPrice();

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'product_name' => $product->name,
                    // Frozen at purchase time: the option may be renamed or
                    // retired later and the invoice must still read correctly.
                    'variant_name' => $variant?->name,
                    'price' => $effectivePrice,
                    // Frozen for the same reason, and a stronger one: purchase
                    // prices move, so what the shop paid for these units is
                    // only knowable now. Null when the product has never come
                    // in through a delivery and the cost is genuinely unknown.
                    'unit_cost' => $this->stock->latestUnitCost($product, $variant),
                    'quantity' => $item->quantity,
                    'total' => round($effectivePrice * $item->quantity, 2),
                ]);

                // Takes the units off the shelf and leaves a ledger row saying why.
                $this->stock->sell($product, $variant, $item->quantity, $order);
            }

            // Clear Cart after successful order
            $this->cartService->clearCart($cart);

            return $order->load(['items.product', 'user']);
        });

        // Outside the transaction: a slow mail server must never hold table locks
        // open, and must never roll back an order that was otherwise successful.
        $this->sendConfirmationEmail($order, $addressData);

        return $order;
    }

    /**
     * Check every line against live stock before we start writing anything.
     *
     * On a variant product the stock is held per option, so a line is measured
     * against the option the shopper actually chose — the product's overall
     * total says nothing about whether that particular one is available.
     *
     * @throws StorefrontException
     */
    protected function assertCartIsPurchasable(Cart $cart): void
    {
        foreach ($cart->items as $item) {
            $product = $item->product;

            if (! $product || ! $product->is_active) {
                throw StorefrontException::unavailable($product->name ?? 'A product in your cart');
            }

            if ($product->has_variants && ! $item->product_variant_id) {
                throw new StorefrontException(
                    "Choose an option for {$product->name} before checking out.",
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }

            $variant = $item->variant;

            if ($item->product_variant_id && (! $variant || ! $variant->is_active)) {
                throw StorefrontException::unavailable($item->displayName());
            }

            // Measured at the branch orders actually ship from. The shop can
            // hold plenty across the showrooms while the one that posts
            // parcels has none, and promising those units would be a lie.
            $available = $this->onlineAvailability($product, $variant);

            // A pre-order product is allowed to ship from a branch that has
            // none: the balance goes negative and the units are owed until the
            // delivery lands.
            if ($available < $item->quantity
                && ! $product->allowsBalance($available - $item->quantity)) {
                throw StorefrontException::outOfStock($item->displayName(), max(0, $available));
            }
        }
    }

    /**
     * How many of something the online branch can actually ship.
     *
     * Falls back to the overall balance when no branch is configured to fulfil
     * online orders, so a shop that has not set one up still sells.
     */
    protected function onlineAvailability(Product $product, ?ProductVariant $variant): int
    {
        $storeId = Store::onlineFulfilment()?->id;

        if (! $storeId) {
            return (int) ($variant?->stock_quantity ?? $product->stock_quantity);
        }

        return (int) ProductStock::forUnit($product->id, $variant?->id)
            ->where('store_id', $storeId)
            ->value('quantity') ?? 0;
    }

    /**
     * Generate a unique order tracking number, retrying on the vanishingly rare collision.
     */
    public function generateOrderNumber(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = 'ORD-'.strtoupper(Str::random(10));

            if (! Order::where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'ORD-'.strtoupper(Str::random(10)).'-'.now()->format('Hisv');
    }

    /**
     * Track order status by order number and phone number.
     *
     * Both numbers are normalised to the canonical 11-digit BD format and compared
     * in full — a partial or suffix match is not proof of ownership.
     */
    public function trackOrder(string $orderNumber, string $phone): ?array
    {
        $orderNumber = strtoupper(trim($orderNumber));
        $cleanPhone = PhoneHelper::normalizeBdPhone($phone);

        if (! $orderNumber || ! $cleanPhone) {
            return null;
        }

        $order = Order::where('order_number', $orderNumber)
            ->with(['items.product.images'])
            ->first();

        if (! $order) {
            return null;
        }

        $orderPhone = PhoneHelper::normalizeBdPhone((string) ($order->shipping_address['phone'] ?? ''));

        // Constant-time exact comparison — no suffix matching.
        if (! $orderPhone || ! hash_equals($orderPhone, $cleanPhone)) {
            return null;
        }

        $statusSteps = [
            'pending' => ['step' => 1, 'label' => 'Order Placed', 'desc' => 'Your order has been received and is awaiting confirmation.'],
            'processing' => ['step' => 2, 'label' => 'Confirmed & Packaging', 'desc' => 'Hardware components are being tested and packaged.'],
            'shipped' => ['step' => 3, 'label' => 'Out for Delivery', 'desc' => 'Package has been handed to courier for express dispatch.'],
            'delivered' => ['step' => 4, 'label' => 'Delivered', 'desc' => 'Package successfully delivered to recipient.'],
            'cancelled' => ['step' => 0, 'label' => 'Cancelled', 'desc' => 'This order was cancelled.'],
        ];

        $currentStepInfo = $statusSteps[$order->status] ?? $statusSteps['pending'];

        return [
            'order_number' => $order->order_number,
            'created_at' => $order->created_at->format('d M, Y h:i A'),
            'status' => $order->status,
            'current_step' => $currentStepInfo['step'],
            'status_label' => $currentStepInfo['label'],
            'status_desc' => $currentStepInfo['desc'],
            'subtotal' => (float) $order->subtotal,
            'shipping_fee' => (float) $order->shipping_fee,
            'discount' => (float) $order->discount,
            'coupon_code' => $order->coupon_code,
            'total' => (float) $order->total,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'shipping_address' => $order->shipping_address,
            'items' => $order->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    // The option is part of what was bought, so tracking has to
                    // name it too — otherwise a customer who chose the 32GB
                    // cannot tell which one is on its way.
                    'variant_name' => $item->variant_name,
                    'price' => (float) $item->price,
                    'quantity' => $item->quantity,
                    'total' => (float) $item->total,
                    'image' => $item->product?->images?->first()?->image_path ?? '/images/product_cpu_i9.jpg',
                ];
            }),
        ];
    }

    /**
     * Move an order to a new status, applying the stock consequence of the move.
     *
     * The rules, all of which the ledger records:
     *   pending -> processing/shipped/delivered   no stock change; the units were
     *                                             already taken at checkout, so
     *                                             approving an order must not
     *                                             take them a second time
     *   pending/processing -> cancelled           reserved units go back, once
     *   shipped/delivered -> cancelled            refused; the goods have left,
     *                                             so this is a return, which
     *                                             records what came back
     *   cancelled -> anything                     refused; cancelled is an end
     *                                             state, and the units are
     *                                             already back on the shelf
     *   shipped/delivered -> returned             handled by returnOrder(), which
     *                                             needs the condition of each item
     *
     * @throws StorefrontException when the order has reached an end state
     */
    public function updateOrderStatus(Order $order, string $status): Order
    {
        if (! in_array($status, Order::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid order status: {$status}");
        }

        if ($order->status === $status) {
            return $order;
        }

        /*
         * Cancelled and returned are both end states.
         *
         * Reopening a cancelled order used to be allowed, and the stock side of
         * it was careful — units came back off the shelf and the move failed if
         * they were gone. What it could not undo is everything outside this
         * table: the customer has been told the order was cancelled, and any
         * refund or credit raised against it still stands. A shop that changes
         * its mind wants a new order, which the returned units can cover
         * straight away, not a rewritten one.
         */
        if ($order->isTerminal()) {
            throw new StorefrontException(
                $order->isReturned()
                    ? 'This order has been returned and can no longer change status.'
                    : 'This order was cancelled and cannot be reopened. Place a new order instead — '
                        .'its stock is already back on the shelf.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        // A return has to say what condition each item came back in, so it
        // cannot be a plain status change.
        if ($status === 'returned') {
            throw new StorefrontException(
                'Process this as a return so each item\'s condition is recorded.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        /*
         * Cancelling restores stock as though the goods never left, so it stops
         * at the point they do.
         *
         * It used to be allowed from any status. Cancelling a delivered order
         * credited the shelf with units the customer is holding; cancelling a
         * shipped one credited it with a parcel the courier still has, and
         * assumed every item would come back intact. Either way the shop
         * believed it held stock it does not have, and would sell it again.
         *
         * Goods that have left come back through a return, which asks how many
         * actually arrived and in what condition, so damaged units are written
         * off rather than resold.
         */
        if ($status === 'cancelled' && ! $order->isCancellable()) {
            throw new StorefrontException(
                'This order has already been dispatched, so cancelling it would put stock back that '
                    .'has left the building. Process it as a return instead, so what actually comes '
                    .'back is recorded.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        DB::transaction(function () use ($order, $status) {
            // Lock the order so two admins clicking at once cannot both decide
            // they are the one releasing the stock.
            $fresh = Order::whereKey($order->id)->lockForUpdate()->first();

            if ($status === 'cancelled') {
                $this->releaseStock($fresh);
            }

            $fresh->update(['status' => $status]);
            $order->setRawAttributes($fresh->getAttributes(), true);
        });

        return $order;
    }

    /**
     * Hand the reserved units back after a cancellation.
     *
     * `stock_released_at` is a latch, not a status check. The old code asked only
     * whether the order was cancelled a moment ago, so cancelled -> pending ->
     * cancelled put the units back twice and created stock that never existed.
     */
    protected function releaseStock(Order $order): void
    {
        if ($order->stock_released_at !== null) {
            return;
        }

        $order->loadMissing('items');

        foreach ($order->items as $item) {
            [$product, $variant, $note] = $this->stockUnitFor($item);

            if (! $product) {
                continue;
            }

            $this->stock->releaseToShelf($product, $variant, $item->quantity, $order, $note);
        }

        $order->forceFill(['stock_released_at' => now()])->save();
    }

    /**
     * Process a return on a delivered order.
     *
     * Each line says how many units came back and in what condition: resellable
     * units go to the shelf, damaged ones are written off so they can never be
     * sold to the next customer. Both are recorded, so the loss is visible.
     *
     * @param  array<int, array{order_item_id:int, resellable?:int, damaged?:int}>  $lines
     *
     * @throws StorefrontException
     */
    public function returnOrder(Order $order, array $lines, ?string $note = null): Order
    {
        if (! $order->isReturnable()) {
            throw new StorefrontException(
                $order->isReturned()
                    ? 'This order has already been returned.'
                    : 'Only a dispatched order can be returned — nothing has left the building yet, '
                        .'so cancel it instead.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $order->loadMissing('items');
        $byId = $order->items->keyBy('id');
        $movedAny = false;

        DB::transaction(function () use ($order, $lines, $byId, $note, &$movedAny) {
            foreach ($lines as $line) {
                $item = $byId->get((int) ($line['order_item_id'] ?? 0));

                if (! $item) {
                    throw new StorefrontException(
                        'One of the returned items does not belong to this order.',
                        422,
                        ApiCode::VALIDATION_ERROR
                    );
                }

                $resellable = max(0, (int) ($line['resellable'] ?? 0));
                $damaged = max(0, (int) ($line['damaged'] ?? 0));
                $total = $resellable + $damaged;

                if ($total === 0) {
                    continue;
                }

                if ($total > $item->returnable_quantity) {
                    throw new StorefrontException(
                        "You cannot return {$total} x {$item->display_name} — only "
                            ."{$item->returnable_quantity} of that line are still outstanding.",
                        422,
                        ApiCode::VALIDATION_ERROR
                    );
                }

                [$product, $variant, $fallbackNote] = $this->stockUnitFor($item);

                if (! $product) {
                    continue;
                }

                if ($resellable > 0) {
                    $this->stock->record($product, $variant, $resellable, StockMovement::RETURN, [
                        'reference' => $order,
                        'note' => trim(($fallbackNote ? $fallbackNote.' ' : '').($note ?? '')) ?: null,
                    ]);
                }

                // Damaged units are accounted for but never put back on the shelf.
                if ($damaged > 0) {
                    $this->stock->record($product, $variant, $damaged, StockMovement::RETURN, [
                        'reference' => $order,
                        'note' => 'Returned damaged — written off below',
                    ]);

                    $this->stock->record($product, $variant, -$damaged, StockMovement::WRITE_OFF, [
                        'reference' => $order,
                        'reason' => 'damaged',
                        'note' => $note ?: 'Damaged on return',
                    ]);
                }

                $item->increment('returned_quantity', $total);
                $movedAny = true;
            }

            if (! $movedAny) {
                throw new StorefrontException(
                    'Enter how many units came back before saving the return.',
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }

            $order->forceFill([
                'status' => 'returned',
                'stock_returned_at' => now(),
            ])->save();
        });

        return $order->fresh('items');
    }

    /**
     * Which shelf an order line belongs to.
     *
     * Usually the option recorded on the line. A line bought before the product
     * gained options has no variant, so its units are steered to the first active
     * option and the movement says so rather than silently vanishing.
     *
     * @return array{0: ?Product, 1: ?ProductVariant, 2: ?string}
     */
    protected function stockUnitFor(OrderItem $item): array
    {
        if (! $item->product_id) {
            return [null, null, null];
        }

        $product = Product::find($item->product_id);

        if (! $product) {
            return [null, null, null];
        }

        if ($item->product_variant_id) {
            $variant = ProductVariant::where('product_id', $product->id)->find($item->product_variant_id);

            if ($variant) {
                return [$product, $variant, null];
            }
        }

        if (! $product->has_variants) {
            return [$product, null, null];
        }

        $fallback = ProductVariant::where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('position')->orderBy('id')
            ->first();

        if (! $fallback) {
            return [$product, null, 'Product has options but none are active; credited to the product.'];
        }

        return [
            $product,
            $fallback,
            'Bought before this product had options; credited to "'.$fallback->name.'".',
        ];
    }

    /**
     * Accepts the storefront's lowercase 'cod' and normalises to the canonical code.
     * Anything the store does not accept falls back to COD rather than being stored.
     */
    protected function normalisePaymentMethod(?string $method): string
    {
        $method = strtoupper(trim((string) $method));

        return in_array($method, Order::PAYMENT_METHODS, true) ? $method : 'COD';
    }

    /**
     * Confirmation email is best-effort: the order is already committed and must
     * stand even if mail delivery fails.
     */
    protected function sendConfirmationEmail(Order $order, array $addressData): void
    {
        try {
            $recipientEmail = $order->user?->email ?? ($addressData['email'] ?? null);

            if ($recipientEmail) {
                Mail::to($recipientEmail)->send(new OrderConfirmationMail($order));
            }
        } catch (\Throwable $e) {
            Log::warning("Could not dispatch OrderConfirmationMail for {$order->order_number}: {$e->getMessage()}");
        }
    }
}
