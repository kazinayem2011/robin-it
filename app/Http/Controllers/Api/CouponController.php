<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CouponController extends Controller
{
    public function __construct(
        protected CartService $cartService
    ) {}

    /**
     * Preview a promo code against the customer's real cart.
     *
     * The subtotal is taken from the server-side cart, not from the request body,
     * so a crafted subtotal can't unlock a minimum-spend coupon. Checkout
     * independently re-validates before the discount is actually granted.
     */
    public function apply(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:50',
        ], [
            'code.required' => 'Please enter a promo code.',
        ]);

        $coupon = Coupon::findByCode($request->input('code'));

        if (! $coupon) {
            return $this->errorResponse(
                'Invalid promo code. Please check and try again.',
                404,
                ApiCode::COUPON_INVALID
            );
        }

        $cart = $this->cartService->findCart(Auth::id(), $request->session()->getId());
        $subtotal = $cart
            ? $this->cartService->calculateTotals($cart)['subtotal']
            : 0.0;

        if ($subtotal <= 0) {
            return $this->errorResponse(
                'Add something to your cart before applying a promo code.',
                422,
                ApiCode::CART_EMPTY
            );
        }

        $result = $coupon->isValidForAmount($subtotal);

        if (! $result['valid']) {
            return $this->errorResponse($result['message'], 422, ApiCode::COUPON_INVALID);
        }

        $totals = $this->cartService->calculateTotals($cart, $result['discount']);

        return $this->successResponse([
            'code' => $coupon->code,
            'discount' => $result['discount'],
            'discount_type' => $coupon->discount_type,
            'discount_value' => $coupon->discount_value,
            'description' => $coupon->description,
            'totals' => $totals,
        ], "Coupon '{$coupon->code}' applied — you save ৳".number_format($result['discount']).'!');
    }
}
