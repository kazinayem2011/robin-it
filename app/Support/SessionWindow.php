<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * How long a signed-in session is allowed to live, which depends on who is
 * signed in.
 *
 * One `web` guard serves both the storefront and the admin panel, so the two
 * windows cannot be two guards' worth of config — they are chosen per request
 * from the role on the user. See config/session.php for the numbers.
 */
class SessionWindow
{
    /**
     * Minutes a session belonging to this user may stay valid. Guests get the
     * shopper window: the cart they are filling lives in that session too.
     */
    public static function minutesFor(?User $user): int
    {
        return $user?->isAdmin()
            ? (int) config('session.admin_lifetime')
            : (int) config('session.customer_lifetime');
    }

    /**
     * Shorten the "remember me" cookie to the window of the session it would
     * recreate.
     *
     * Laravel queues the recaller for 400 days flat. Left alone it hands an
     * admin who ticked the box a way back in months after their week was up —
     * the browser sends it once the session cookie is gone, and the guard
     * signs them straight back in with a brand-new session.
     *
     * Called after login, because the box is ticked before we know whose it
     * is. Re-queueing under the same name replaces what the guard queued.
     */
    public static function capRememberCookie(?User $user): void
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        $name = $guard->getRecallerName();

        $recaller = Cookie::queued($name);

        // Not ticked: there is no recaller to shorten.
        if (! $recaller) {
            return;
        }

        // Same jar defaults for path, domain, secure and same-site as the
        // guard used a moment ago — only the expiry differs.
        Cookie::queue(Cookie::make($name, $recaller->getValue(), self::minutesFor($user)));
    }
}
