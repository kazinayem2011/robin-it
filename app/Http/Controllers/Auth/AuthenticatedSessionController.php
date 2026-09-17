<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\CartService;
use App\Services\ComparisonService;
use App\Support\SessionWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(Request $request): Response
    {
        /*
         * Where to go back to afterwards, from ?redirect=. Sent by the
         * storefront when a session runs out mid-action, and by checkout's
         * "sign in to order with it" — and read by nothing until now, so both
         * landed on the dashboard instead.
         *
         * A path on this site only. Anything that could leave it — a full
         * address, `//host`, a backslash a browser would read as a slash,
         * whitespace — is ignored rather than trusted.
         */
        $next = $request->query('redirect');

        if (is_string($next) && preg_match('#^/(?![/\\\\])[^\\\\\s]*$#D', $next)) {
            $request->session()->put('url.intended', url($next));
        }

        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(
        LoginRequest $request,
        CartService $cartService,
        ComparisonService $comparisons,
    ): RedirectResponse {
        // Captured before regenerate(), which issues a brand-new session id.
        $guestSessionId = $request->session()->getId();

        $request->authenticate();

        $request->session()->regenerate();

        $user = Auth::user();

        // Carry anything the shopper added before signing in onto their account.
        if ($user) {
            $cartService->mergeGuestCart($user->id, $guestSessionId);
            $comparisons->mergeGuestList($user->id, $guestSessionId);
        }

        // Only now is it known whose "remember me" this is, and a staff one is
        // not allowed to outlive the week their session gets.
        SessionWindow::capRememberCookie($user);

        // Redirect admins to Admin Dashboard, customers to User Dashboard / intended page
        if ($user && $user->isAdmin()) {
            return redirect()->intended(route('admin.dashboard', absolute: false));
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
