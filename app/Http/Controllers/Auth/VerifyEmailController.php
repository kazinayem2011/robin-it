<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CartService;
use App\Services\ComparisonService;
use App\Support\Roles;
use App\Support\SessionWindow;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The link in the "verify your email" message.
 *
 * It used to need the customer signed in first. Opened on another device, or
 * after the session had gone, it sent them to the sign-in page and confirmed
 * nothing until they came back to the link — which most people never do. The
 * link is signed and reached only through the inbox it confirms, which is as
 * good a proof of who is holding it as a password, so now it signs them in as
 * well.
 *
 * Not somebody else, though. If a different account is signed in on this
 * browser — a shared family computer, say — the address is still confirmed,
 * but nobody is switched: quietly swapping the account under somebody is worse
 * than a note saying what happened.
 *
 * Staff and suspended accounts are confirmed and not signed in. Staff sign in
 * with their password — an email link is not what should open the admin panel
 * — and a suspended account does not sign in at all.
 */
class VerifyEmailController extends Controller
{
    public function __invoke(
        Request $request,
        CartService $carts,
        ComparisonService $comparisons,
        string $id,
        string $hash,
    ): RedirectResponse {
        $user = User::find($id);

        // The signature covers the id and hash, so this is only a stale
        // link to an account that has since changed its address or gone.
        if (! $user || ! hash_equals(sha1((string) $user->getEmailForVerification()), $hash)) {
            abort(403, 'This verification link is not valid.');
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        $signedIn = $request->user();

        if ($signedIn && $signedIn->id !== $user->id) {
            // `info` is shared with every page, so the dashboard shows it.
            return redirect(route('dashboard', absolute: false).'?verified=1')->with(
                'info',
                "{$user->email} is confirmed. You are still signed in as {$signedIn->name}; "
                .'sign out and back in to use that account.'
            );
        }

        if (! $signedIn) {
            if (Roles::isStaff($user->role) || $user->is_active === false) {
                return redirect()->route('login')
                    ->with('status', 'Your email address is confirmed. Sign in to continue.');
            }

            /*
             * As the sign-in form does it: a fresh session id, the guest's
             * cart and comparison carried onto the account, and the session
             * held to the customer's window.
             */
            $guestSessionId = $request->session()->getId();

            Auth::login($user);
            $request->session()->regenerate();

            $carts->mergeGuestCart($user->id, $guestSessionId);
            $comparisons->mergeGuestList($user->id, $guestSessionId);

            SessionWindow::capRememberCookie($user);
        }

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
