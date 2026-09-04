<?php

namespace App\Http\Middleware;

use App\Enums\ApiCode;
use App\Support\SessionWindow;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds each session to the window its owner is entitled to: two months for a
 * shopper, a week for staff.
 */
class EnforceSessionWindow
{
    use ApiResponse;

    /** When the member of staff on this session was last seen, as a timestamp. */
    private const LAST_SEEN = 'admin_last_seen_at';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $minutes = SessionWindow::minutesFor($user);

        /*
         * StartSession stamps the session cookie from config on its way out —
         * which is after this middleware has run — so writing the value here
         * is what actually hands staff a shorter cookie than a shopper. Set on
         * every request rather than only staff ones, so the narrowed number is
         * never left behind for whoever is served next.
         */
        config(['session.lifetime' => $minutes]);

        if (! $user?->isAdmin()) {
            return $next($request);
        }

        /*
         * The other half of the same week. The database handler was built with
         * session.lifetime as it stood when the session started — the shopper's
         * two months — and expires rows by that, so an admin session left idle
         * past its own window has to be cut here. Without this the week would
         * be nothing but a cookie the browser is trusted to throw away.
         */
        $session = $request->session();

        $lastSeen = $session->get(self::LAST_SEEN);

        if ($lastSeen !== null && $lastSeen < now()->subMinutes($minutes)->getTimestamp()) {
            return $this->expire($request);
        }

        $session->put(self::LAST_SEEN, now()->getTimestamp());

        return $next($request);
    }

    /**
     * Sign the admin out and send them back to the login screen. logout() also
     * forgets the recaller, so a ticked "remember me" cannot undo this.
     */
    private function expire(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = 'Your session has expired. Please sign in again.';

        // Same test for an API request as bootstrap/app.php and AdminMiddleware.
        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->errorResponse($message, 401, ApiCode::UNAUTHORIZED);
        }

        return redirect()->route('login')->with('status', $message);
    }
}
