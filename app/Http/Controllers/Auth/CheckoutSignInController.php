<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\CartService;
use App\Services\ComparisonService;
use Illuminate\Http\JsonResponse;

/**
 * Signing in from inside checkout, without leaving it.
 *
 * A guest whose email already has an account is asked which account the order
 * is for. Picking that one means its password, and sending them to the sign-in
 * page lost the delivery details they had just typed. This signs them in where
 * they are and answers in JSON, so the page carries on with what is on it.
 *
 * The same checks as the sign-in form, because it is the same LoginRequest:
 * email or mobile, the attempt limit, and no way in for a suspended account.
 * And the same aftermath: a fresh session id, and the guest's cart and
 * comparison carried onto the account.
 */
class CheckoutSignInController extends Controller
{
    public function store(LoginRequest $request, CartService $carts, ComparisonService $comparisons): JsonResponse
    {
        // Captured before regenerate(), which issues a brand-new session id.
        $guestSessionId = $request->session()->getId();

        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        $carts->mergeGuestCart($user->id, $guestSessionId);
        $comparisons->mergeGuestList($user->id, $guestSessionId);

        return $this->successResponse([
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
        ], 'Signed in as '.$user->name.'.');
    }
}
