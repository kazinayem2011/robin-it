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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        protected CartService $cartService
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
        $cart->load('items.product');

        if ($cart->items->isEmpty()) {
            throw StorefrontException::emptyCart();
        }

        // Fail fast with a clear message before opening a transaction.
        $this->assertCartIsPurchasable($cart);

        $order = DB::transaction(function () use ($cart, $addressData, $userId, $sessionId, $coupon) {
            $discount = 0.0;

            if ($coupon) {
                $preview = $this->cartService->calculateTotals($cart);
                // Recomputed server-side — never trust a discount posted by the browser.
                $discount = $coupon->discountFor($preview['subtotal']);

                if (! $coupon->redeem()) {
                    throw new StorefrontException(
                        'This coupon has just reached its usage limit. Please remove it and try again.',
                        422,
                        ApiCode::COUPON_INVALID
                    );
                }
            }

            $totals = $this->cartService->calculateTotals($cart, $discount);

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $userId,
                'session_id' => $userId ? null : $sessionId,
                'subtotal' => $totals['subtotal'],
                'shipping_fee' => $totals['shipping_fee'],
                'discount' => $totals['discount'],
                'coupon_code' => $coupon?->code,
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

                $effectivePrice = $product->effective_price;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'price' => $effectivePrice,
                    'quantity' => $item->quantity,
                    'total' => round($effectivePrice * $item->quantity, 2),
                ]);

                $this->reserveStock($product, $item->quantity);
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
     * Reserve stock with a single conditional UPDATE. Two shoppers racing for the
     * last unit can't both succeed: whichever loses matches zero rows and is told so.
     *
     * @throws StorefrontException
     */
    protected function reserveStock(Product $product, int $quantity): void
    {
        $affected = Product::whereKey($product->id)
            ->where('is_active', true)
            ->where('stock_quantity', '>=', $quantity)
            ->update([
                'stock_quantity' => DB::raw("stock_quantity - {$quantity}"),
            ]);

        if ($affected === 0) {
            throw StorefrontException::outOfStock(
                $product->name,
                max(0, (int) Product::whereKey($product->id)->value('stock_quantity'))
            );
        }
    }

    /**
     * Check every line against live stock before we start writing anything.
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

            if ($product->stock_quantity < $item->quantity) {
                throw StorefrontException::outOfStock($product->name, max(0, $product->stock_quantity));
            }
        }
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
                    'price' => (float) $item->price,
                    'quantity' => $item->quantity,
                    'total' => (float) $item->total,
                    'image' => $item->product?->images?->first()?->image_path ?? '/images/product_cpu_i9.jpg',
                ];
            }),
        ];
    }

    /**
     * Update order status with validation. Cancelling returns reserved stock to the shelf.
     */
    public function updateOrderStatus(Order $order, string $status): Order
    {
        if (! in_array($status, Order::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid order status: {$status}");
        }

        $wasCancelled = $order->isCancelled();

        DB::transaction(function () use ($order, $status, $wasCancelled) {
            $order->update(['status' => $status]);

            if ($status === 'cancelled' && ! $wasCancelled) {
                $this->restock($order);
            }
        });

        return $order;
    }

    /**
     * Put stock back when an order is cancelled, so the units become sellable again.
     */
    protected function restock(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            Product::whereKey($item->product_id)
                ->update(['stock_quantity' => DB::raw("stock_quantity + {$item->quantity}")]);
        }
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
