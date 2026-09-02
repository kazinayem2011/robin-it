<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Services\AddressBook;
use App\Services\CartService;
use App\Services\OrderService;
use App\Support\ShippingRates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckoutController extends Controller
{
    public function __construct(
        protected CartService $cartService,
        protected OrderService $orderService
    ) {}

    public function process(Request $request): JsonResponse
    {
        // The rules judge the number, not its punctuation.
        PhoneHelper::canonicalise($request, 'phone');

        /*
         * The delivery address is one line and a zone.
         *
         * It used to be a street, a city and an optional area, and the city was
         * then searched for the word "dhaka" to decide what delivery cost. That
         * only ever worked because the city had a box of its own; asking for
         * the address as one line and still guessing from it would charge
         * "Dhaka Road, Chittagong" the local rate. So the customer says which
         * zone they are in, and the address itself is theirs to write.
         *
         * street_address and city are still accepted, unvalidated against the
         * new shape, so a form or a saved draft posting the old fields is not
         * turned away mid-checkout.
         */
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:20', PhoneHelper::RULE],
            'address' => 'required_without:street_address|nullable|string|max:500',
            'delivery_zone' => 'nullable|string|in:'.implode(',', ShippingRates::ZONES),
            'street_address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'zone' => 'nullable|string|max:100',
            // Only methods the store can actually take payment for.
            'payment_method' => 'nullable|string|in:'.implode(',', Order::PAYMENT_METHODS),
            'payment' => 'nullable|string|in:cod,COD',
            'coupon_code' => 'nullable|string|max:50',
        ], [
            'phone.regex' => 'Please enter a valid 11-digit Bangladeshi mobile number so we can reach you about delivery.',
            'address.required_without' => 'We need an address to deliver your order to.',
            'delivery_zone.in' => 'Choose whether the delivery is inside or outside Dhaka.',
            'payment_method.in' => 'We currently accept Cash on Delivery only.',
            'payment.in' => 'We currently accept Cash on Delivery only.',
        ]);

        /*
         * One line, whichever field it arrived in. Everything downstream — the
         * order record, the address book, the invoice — reads street_address,
         * so the new single field is folded into it rather than adding a second
         * name for the same thing.
         */
        $validated['street_address'] = trim((string) (
            $validated['address'] ?? $validated['street_address'] ?? ''
        ));

        // Present whether or not it was sent: everything downstream reads these
        // keys directly, and a missing one is a fatal rather than a blank.
        $validated['city'] = $validated['city'] ?? null;
        $validated['zone'] = $validated['zone'] ?? null;
        $validated['delivery_zone'] = ShippingRates::normaliseZone($validated['delivery_zone'] ?? null);

        // The storefront form posts `payment`; the API contract is `payment_method`.
        $validated['payment_method'] = $validated['payment_method'] ?? $validated['payment'] ?? 'COD';

        $cart = $this->cartService->findCart(
            Auth::id(),
            $request->session()->getId()
        );

        if (! $cart || $cart->items()->count() === 0) {
            return $this->errorResponse(
                'Your cart is empty. Add a product before checking out.',
                422,
                ApiCode::CART_EMPTY
            );
        }

        // Re-validate the coupon server-side; the posted discount amount is ignored.
        $coupon = null;
        if (! empty($validated['coupon_code'])) {
            $coupon = Coupon::findByCode($validated['coupon_code']);

            if (! $coupon) {
                return $this->errorResponse(
                    'That promo code is not valid. Remove it to continue.',
                    422,
                    ApiCode::COUPON_INVALID
                );
            }

            // Judged against the lines the coupon actually covers, so a
            // category promo cannot discount the rest of the basket.
            $check = $coupon->isValidForCart($cart, Auth::id());

            if (! $check['valid']) {
                return $this->errorResponse($check['message'], 422, ApiCode::COUPON_INVALID);
            }
        }

        try {
            $order = $this->orderService->placeOrder(
                $cart,
                $validated,
                Auth::id(),
                $request->session()->getId(),
                $coupon
            );

            // After the order, not before: a checkout that fails on stock or
            // a bad coupon should not leave an address behind for an order
            // that never happened.
            AddressBook::remember($request->user(), $validated);

            return $this->successResponse([
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'subtotal' => $order->subtotal,
                'shipping_fee' => $order->shipping_fee,
                'discount' => $order->discount,
                'coupon_code' => $order->coupon_code,
                'total' => $order->total,
            ], 'Order placed successfully. A confirmation has been sent to you.', 201);
        } catch (StorefrontException $e) {
            return $this->storefrontErrorResponse($e);
        }
    }

    /**
     * Track live order status by order number and phone.
     */
    public function track(Request $request): JsonResponse
    {
        // The rules judge the number, not its punctuation.
        PhoneHelper::canonicalise($request, 'phone');

        $validated = $request->validate([
            'order_number' => 'required|string|max:64',
            /*
             * A full mobile number is how a guest proves the order is theirs.
             * Someone signed in has already proved it by signing in, so the
             * number is optional for them — and an account is not required to
             * have one: registering with an email and leaving the phone blank
             * is allowed. The service still refuses anyone else's order.
             */
            'phone' => [
                $request->user() ? 'nullable' : 'required',
                'string', 'max:20', PhoneHelper::RULE,
            ],
        ], [
            'phone.regex' => 'Please enter the full 11-digit mobile number used when placing the order.',
        ]);

        $trackingData = $this->orderService->trackOrder(
            $validated['order_number'],
            $validated['phone'] ?? null,
            $request->user()
        );

        if (! $trackingData) {
            // Deliberately identical for "no such order" and "wrong phone" so the
            // endpoint can't be used to confirm which order numbers exist.
            return $this->errorResponse(
                'No order found matching the provided Order Number and Mobile Number.',
                404,
                ApiCode::NOT_FOUND
            );
        }

        return $this->successResponse($trackingData, 'Order tracking details retrieved successfully.');
    }
}
