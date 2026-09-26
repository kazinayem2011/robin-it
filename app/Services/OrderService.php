<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Helpers\PhoneHelper;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderStatusUpdatedMail;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductStock;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\Courier\CourierDriverRegistry;
use App\Support\BrandDetails;
use App\Support\ShippingRates;
use App\Support\SmsTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        protected CartService $cartService,
        protected StockService $stock,
        protected CourierDriverRegistry $couriers,
        protected ShopNotifier $notifier,
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
        ?Coupon $coupon = null,
        // A counter sale ships from the branch it was made at; online, the
        // default branch, then whichever has it.
        ?int $storeId = null,
    ): Order {
        $cart->load('items.product', 'items.variant');

        if ($cart->items->isEmpty()) {
            throw StorefrontException::emptyCart();
        }

        // Fail fast with a clear message before opening a transaction.
        $this->assertCartIsPurchasable($cart);

        $order = DB::transaction(function () use ($cart, $addressData, $userId, $sessionId, $coupon, $storeId) {
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
            $totals = $this->cartService->calculateTotals(
                $cart,
                $discount,
                $addressData['city'] ?? null,
                ShippingRates::normaliseZone($addressData['delivery_zone'] ?? null),
            );

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
                    // Optional at checkout, and the only address a guest's
                    // confirmation and status emails can go to.
                    'email' => filled($addressData['email'] ?? null) ? strtolower(trim($addressData['email'])) : null,
                    'street_address' => $addressData['street_address'],
                    'city' => $addressData['city'],
                    'zone' => $addressData['zone'] ?? null,
                    /*
                     * What the customer said the destination is, kept on the
                     * order so re-pricing an edit later reaches the same answer
                     * the customer was charged. Reading the city again would
                     * re-guess it, and a guess that changes after the fact is
                     * worse than no guess at all.
                     */
                    'delivery_zone' => ShippingRates::normaliseZone($addressData['delivery_zone'] ?? null),
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

                // Takes the units off the shelves that hold them and leaves a
                // ledger row per branch saying why.
                $this->stock->sellForOrder($product, $variant, $item->quantity, $order, $storeId);
            }

            // Clear Cart after successful order
            $this->cartService->clearCart($cart);

            return $order->load(['items.product', 'user']);
        });

        // Outside the transaction: a slow mail server must never hold table locks
        // open, and must never roll back an order that was otherwise successful.
        $this->sendConfirmationEmail($order, $addressData);

        // Same reasoning: whoever handles orders is told, and a notification
        // that fails to send must not undo the sale.
        $this->notifier->orderPlaced($order);

        return $order;
    }

    /**
     * Take an order at the counter or over the phone.
     *
     * The shop could only receive an order through the storefront, so a
     * customer ringing up, or standing at the counter, could not be served
     * without asking them to go home and use the website. Every other part of
     * the app assumes an order exists: stock, serials, payments, delivery,
     * the margin report.
     *
     * This deliberately builds a cart and hands it to placeOrder rather than
     * writing an order itself. Everything that makes a storefront order
     * correct — the stock check against the branch that actually ships, the
     * pre-order allowance, the atomic reservation, the coupon redeemed under a
     * row lock, the totals, the confirmation — is in there, and a second path
     * would be a second set of those rules to keep in step. There would be no
     * way to notice they had drifted until a counter sale oversold something.
     *
     * @param  array<int, array{product_id:int, product_variant_id?:?int, quantity:int}>  $lines
     */
    public function placeForCustomer(
        array $lines,
        array $addressData,
        ?User $customer = null,
        ?Coupon $coupon = null,
        ?int $storeId = null,
    ): Order {
        $lines = array_values(array_filter($lines, fn ($l) => (int) ($l['quantity'] ?? 0) > 0));

        if ($lines === []) {
            throw new StorefrontException(
                'Add at least one product to the order.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        /*
         * A cart of its own, never the customer's.
         *
         * Writing into the cart they already have would empty it when this
         * order is placed — somebody who rings up to buy one thing would lose
         * the basket they had been building on the website. The session id is
         * random so it can never collide with a live browser session either.
         */
        $cart = Cart::create([
            'user_id' => null,
            'session_id' => 'counter-'.Str::random(32),
        ]);

        try {
            foreach ($lines as $line) {
                CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => (int) $line['product_id'],
                    'product_variant_id' => $line['product_variant_id'] ?? null,
                    'quantity' => (int) $line['quantity'],
                ]);
            }

            return $this->placeOrder(
                $cart->fresh(),
                $addressData,
                $customer?->id,
                $cart->session_id,
                $coupon,
                $storeId
            );
        } catch (\Throwable $e) {
            /*
             * A refused order must not leave its scratch cart behind. Without
             * this, every out-of-stock attempt at the counter leaves a row
             * nobody will ever look at, and the carts table fills with them.
             */
            $cart->items()->delete();
            $cart->delete();

            throw $e;
        }
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

            // Everything the branches hold between them: an order takes from
            // whichever has it (StockService::sellForOrder), so a unit in a
            // showroom is as sellable as one in the warehouse.
            $available = $this->onlineAvailability($product, $variant);

            // A pre-order product is allowed to ship from a branch that has
            // none: the balance goes negative and the units are owed until the
            // delivery lands.
            if ($available < $item->quantity
                && ! $product->allowsBalance($available - $item->quantity)
                // Some in stock: the order is taken, the rest owed and flagged.
                && ! $product->takesOrdersBeyondStock($available)) {
                // Past a pre-order limit is not "out of stock": say the number.
                $ceiling = $product->allowsPreorder() ? $product->sellableCeiling($available) : null;

                throw $ceiling !== null
                    ? StorefrontException::preorderLimit($item->displayName(), $ceiling)
                    : StorefrontException::outOfStock($item->displayName(), max(0, $available));
            }
        }
    }

    /**
     * How many of something the branches can ship between them.
     *
     * It was the online branch alone, while the storefront's "In Stock" and
     * the cart counted every branch — so something held only in a showroom
     * was offered, carted, and then refused at checkout. An order now takes
     * from any branch that holds stock, so this counts them all. Without any
     * branch set up, the overall balance.
     */
    protected function onlineAvailability(Product $product, ?ProductVariant $variant): int
    {
        $branches = Store::holdsStock()->pluck('id');

        if ($branches->isEmpty()) {
            return (int) ($variant?->stock_quantity ?? $product->stock_quantity);
        }

        return (int) ProductStock::forUnit($product->id, $variant?->id)
            ->whereIn('store_id', $branches)
            ->sum('quantity');
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
     * Track an order, for whoever can show it is theirs.
     *
     * The phone number is how a guest proves that. Someone signed in has
     * already proved it by signing in, and their own orders open without it —
     * which matters because an account is not required to have a phone at all:
     * registering with an email and leaving the number blank is allowed, and
     * that customer could otherwise never track an order they had placed.
     *
     * Signing in is not a skeleton key. It opens the orders on that account
     * and nothing else; anyone else's still wants the number on the order.
     *
     * The key in the link the order's messages carry is the third way: it was
     * only ever sent to the order's own phone and email.
     *
     * @param  string|null  $phone  required of a guest; ignored when the order
     *                              belongs to the signed-in customer
     * @param  User|null  $viewer  whoever is asking, if they are signed in
     * @param  string|null  $key  Order::trackingKey(), from a tracking link
     */
    public function trackOrder(string $orderNumber, ?string $phone = null, ?User $viewer = null, ?string $key = null): ?array
    {
        $orderNumber = Order::normalizeNumber($orderNumber);

        if (! $orderNumber) {
            return null;
        }

        $order = Order::where('order_number', $orderNumber)
            ->with(['items.product.images', 'items.variant', 'courier'])
            ->first();

        if (! $order) {
            return null;
        }

        if (! $this->mayTrack($order, $phone, $viewer, $key)) {
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
            // Who has the parcel and how to chase it — the question this page
            // exists to answer, and one it could not answer before.
            'courier' => $order->courier?->name,
            'courier_phone' => $order->courier?->phone,
            'tracking_number' => $order->tracking_number,
            'tracking_url' => $order->tracking_url,
            'dispatched_at' => $order->dispatched_at?->format('d M, Y h:i A'),
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
                    /*
                     * The option's own photo when it has one. An option can
                     * carry its own shots — a white card looks nothing like
                     * the black one — and `image_url` on the variant is its
                     * lead one. Reading the product's alone showed the generic
                     * shot for every option, and made choosing a lead photo
                     * for an option look as though it had not saved.
                     */
                    'image' => $item->variant?->image_url
                        ?: ($item->product?->images?->first()?->image_url ?? ProductImage::PLACEHOLDER),
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
    /**
     * Whether this asker may see this order.
     *
     * A wrong number and a number that is not there are the same answer, as is
     * an order that does not exist — the endpoint must not become a way to
     * find out which order numbers are real.
     */
    private function mayTrack(Order $order, ?string $phone, ?User $viewer, ?string $key = null): bool
    {
        if ($viewer && $order->user_id && $order->user_id === $viewer->id) {
            return true;
        }

        // The key from the order's own messages, which only its customer was sent.
        if ($order->opensWithTrackingKey($key)) {
            return true;
        }

        $given = PhoneHelper::normalizeBdPhone((string) $phone);
        $onOrder = PhoneHelper::normalizeBdPhone((string) ($order->shipping_address['phone'] ?? ''));

        if (! $given || ! $onOrder) {
            return false;
        }

        // Constant-time exact comparison — no suffix matching.
        return hash_equals($onOrder, $given);
    }

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
        $changed = true;

        DB::transaction(function () use ($order, $status, &$changed) {
            // Lock the order so two admins clicking at once cannot both decide
            // they are the one releasing the stock.
            $fresh = Order::whereKey($order->id)->lockForUpdate()->first();

            // Somebody else got there first with the same change.
            if ($fresh->status === $status) {
                $changed = false;

                return;
            }

            /*
             * Both guards asked of the locked row, not of the copy the caller
             * brought. They used to run before the transaction, so one admin
             * cancelling while another moved the same order on was invisible
             * here: the stale copy still said pending, and a cancelled order
             * could be marched on to shipped after its units had already gone
             * back to the shelf.
             */
            if ($fresh->isTerminal()) {
                throw new StorefrontException(
                    $fresh->isReturned()
                        ? 'This order has been returned and can no longer change status.'
                        : 'This order was cancelled and cannot be reopened. Place a new order instead — '
                            .'its stock is already back on the shelf.',
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }

            if ($status === 'cancelled' && ! $fresh->isCancellable()) {
                throw new StorefrontException(
                    'This order has already been dispatched, so cancelling it would put stock back that '
                        .'has left the building. Process it as a return instead, so what actually comes '
                        .'back is recorded.',
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }

            if ($status === 'cancelled') {
                $this->releaseStock($fresh);
            }

            $fresh->update(['status' => $status]);
            $order->setRawAttributes($fresh->getAttributes(), true);
        });

        // After the commit: the customer is told about a status that is
        // actually saved, never one a failed transaction rolled back.
        if ($changed) {
            $this->notifier->orderStatusChanged($order, $status);
        }

        return $order;
    }

    /**
     * Hand a parcel to a carrier.
     *
     * Marking an order shipped used to be a bare status change, which left a
     * customer ringing up to ask where their delivery was and nobody able to
     * say. Dispatching records who took it and the number to chase it with,
     * and moves the order on in the same step so the two cannot disagree.
     *
     * @throws StorefrontException
     */
    public function dispatchOrder(Order $order, Courier $courier, ?string $trackingNumber = null): Order
    {
        if ($order->isTerminal()) {
            throw new StorefrontException(
                'This order has been '.$order->status.' and can no longer be dispatched.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        /*
         * Book with the carrier before touching the order.
         *
         * If the carrier refuses — an address it will not serve, a missing
         * phone number, an expired key — the exception propagates and the
         * order stays where it was. Marking it shipped and then failing to
         * book would leave a customer told their parcel is on its way when
         * nobody has it.
         *
         * Carriers with no API, and integrated ones with no credentials saved,
         * fall back to the number the admin typed.
         */
        $consignment = null;

        if ($courier->canBook()) {
            $consignment = $this->couriers->for($courier)->createConsignment($order, $courier);
        }

        $tracking = $consignment?->trackingNumber ?? $trackingNumber;

        DB::transaction(function () use ($order, $courier, $tracking) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->first();

            // Asked again from behind the lock. The check above fails fast so a
            // doomed dispatch does not book with the carrier first; this one is
            // what stops an order cancelled since then from leaving anyway.
            if ($fresh->isTerminal()) {
                throw new StorefrontException(
                    'This order has been '.$fresh->status.' and can no longer be dispatched.',
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }

            $fresh->forceFill([
                'courier_id' => $courier->id,
                'tracking_number' => filled($tracking) ? trim($tracking) : null,
                // The moment it left, which is not the same as the moment the
                // row was last touched.
                'dispatched_at' => $fresh->dispatched_at ?? now(),
                'status' => 'shipped',
            ])->save();

            $order->setRawAttributes($fresh->getAttributes(), true);

            /*
             * The units on this order are now the customer's, so the serials
             * follow them out of the door — oldest first, which is what a shop
             * picks and what stops stock ageing into unsellability.
             *
             * Inside the transaction with the dispatch: a parcel recorded as
             * gone whose units are still shown on the shelf is the kind of
             * disagreement nobody finds until a warranty claim.
             */
            app(SerialService::class)->assignToOrder($order->load('items.product'));
        });

        // The bell as well as the email and the text. Dispatch moves the order
        // to shipped without going through updateOrderStatus(), which is where
        // the bell is rung for every other move, so it was never rung here.
        $this->notifier->orderStatusChanged($order, 'shipped');
        $this->notifyStatusChange($order);

        return $order;
    }

    /**
     * Tell the customer their order moved, without letting the message hold it
     * up.
     *
     * Email and SMS both, and separately: a shop here can rely on the text
     * being read and the email not being, and a gateway that is down must not
     * stop the mail going out or the order from having moved.
     *
     * Public for the admin's status change, which moves the order through
     * updateOrderStatus() — that rings the bell — and then calls this.
     */
    public function notifyStatusChange(Order $order): void
    {
        try {
            if ($email = $order->notifiableEmail()) {
                Mail::to($email)->send(new OrderStatusUpdatedMail($order));
            }
        } catch (\Throwable $e) {
            Log::warning("Could not dispatch OrderStatusUpdatedMail: {$e->getMessage()}");
        }

        $this->notifyBySms($order);
    }

    /**
     * The same news, by text.
     *
     * Only for the statuses a customer can act on. SmsTemplates returns null
     * for the rest, because a message that says nothing still costs the shop
     * money and still interrupts somebody's evening.
     *
     * Each status is its own switch in Settings. Dispatch and delivery are off
     * by default because the courier texts those itself, and a shop paying to
     * repeat them is paying twice for one piece of news.
     */
    protected function notifyBySms(Order $order): void
    {
        $phone = $order->notifiablePhone();

        if (! $phone) {
            return;
        }

        try {
            $order->loadMissing('courier');
            $sms = app(SmsService::class);

            $sms->sendEvent(
                $order->status,
                $phone,
                SmsTemplates::statusChanged($order, BrandDetails::name())
            );

            /*
             * A parcel going out with money still owed on it gets a second
             * line: cash on delivery only works if the cash is in the house
             * when the rider knocks, and a customer who has to go and find it
             * is a customer who refuses the delivery.
             *
             * Its own switch, and on by default even though dispatch is off:
             * the courier tells the customer a parcel is coming, but it has no
             * idea how much of it is still to pay.
             *
             * Only on dispatch, and only when something is actually owed — a
             * fully-paid order or a deposit that covered it says nothing.
             */
            if ($order->status === 'shipped' && $order->amount_due > 0) {
                $sms->sendEvent(
                    'payment_due',
                    $phone,
                    SmsTemplates::paymentDue($order, $order->amount_due, BrandDetails::name())
                );
            }
        } catch (\Throwable $e) {
            Log::warning("Could not send the order SMS: {$e->getMessage()}");
        }
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

            // Back to the branch each unit left, read from the ledger.
            $this->stock->restoreForOrder($product, $variant, $item->quantity, $order, StockMovement::CANCELLATION, $note);
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
        $movedAny = false;

        DB::transaction(function () use ($order, $lines, $note, &$movedAny) {
            /*
             * Lock the order and read its state from behind that lock.
             *
             * Both the returnable check and each line's outstanding count used
             * to be read before the transaction opened. Two people on the
             * returns desk with the same order open therefore both saw a
             * delivered order and nothing returned yet, and the units went back
             * on the shelf twice — once per desk.
             */
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $fresh->isReturnable()) {
                throw new StorefrontException(
                    $fresh->isReturned()
                        ? 'This order has already been returned.'
                        : 'Only a dispatched order can be returned — nothing has left the building yet, '
                            .'so cancel it instead.',
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }

            $byId = $fresh->items()->get()->keyBy('id');

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

                // Back to the branch each unit left, read from the ledger.
                if ($resellable > 0) {
                    $this->stock->restoreForOrder(
                        $product, $variant, $resellable, $order, StockMovement::RETURN,
                        trim(($fallbackNote ? $fallbackNote.' ' : '').($note ?? '')) ?: null,
                    );
                }

                // Damaged units are accounted for but never put back on the
                // shelf: back to the branch, then written off from that one.
                if ($damaged > 0) {
                    $landed = $this->stock->restoreForOrder(
                        $product, $variant, $damaged, $order, StockMovement::RETURN,
                        'Returned damaged — written off below',
                    );

                    foreach ($landed ?: [0 => $damaged] as $storeId => $units) {
                        $this->stock->record($product, $variant, -$units, StockMovement::WRITE_OFF, [
                            'reference' => $order,
                            'reason' => 'damaged',
                            'note' => $note ?: 'Damaged on return',
                        ] + ($storeId ? ['store_id' => $storeId] : []));
                    }
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

            // Written through the locked row, then mirrored onto the caller's
            // instance so it does not hand back a stale status.
            $fresh->forceFill([
                'status' => 'returned',
                'stock_returned_at' => now(),
            ])->save();

            $order->setRawAttributes($fresh->getAttributes(), true);

            /*
             * The units are the shop's again. The serials follow the same
             * resellable/damaged split the stock ledger just recorded — a
             * working unit goes back on the shelf where the next sale can pick
             * it up, a damaged one is written off.
             */
            app(SerialService::class)->returnFromOrder($fresh, $lines);
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
            if ($recipientEmail = $order->notifiableEmail()) {
                Mail::to($recipientEmail)->send(new OrderConfirmationMail($order));
            }
        } catch (\Throwable $e) {
            Log::warning("Could not dispatch OrderConfirmationMail for {$order->order_number}: {$e->getMessage()}");
        }

        /*
         * And by text, which for most customers here is the one that gets
         * read. Separately from the mail: a gateway being down must not stop
         * the email, and neither must stop an order that is already committed.
         *
         * Skipped outright with no number to send to. An order can be placed
         * with an email and nothing else, and recipient_phone answers "N/A"
         * for the benefit of a printed invoice — sending that to a gateway
         * spends an attempt dialling two letters.
         */
        if ($phone = $order->notifiablePhone()) {
            try {
                app(SmsService::class)->sendEvent(
                    'order_placed',
                    $phone,
                    SmsTemplates::orderPlaced($order, BrandDetails::name())
                );
            } catch (\Throwable $e) {
                Log::warning("Could not send the order SMS for {$order->order_number}: {$e->getMessage()}");
            }
        }
    }
}
