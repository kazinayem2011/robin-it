<?php

namespace App\Http\Middleware;

use App\Enums\ApiCode;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    use ApiResponse;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isAdmin()) {
            return $next($request);
        }

        /*
         * `is('api/*')` as well as expectsJson(): every admin write is an axios
         * call under /api now, and a client that forgets the Accept header
         * would otherwise be answered with a redirect to a customer page —
         * which axios follows and then fails to parse. This mirrors the test in
         * bootstrap/app.php so both agree on what an API request is.
         */
        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->errorResponse(
                'Unauthorized. Admin privileges required.',
                403,
                ApiCode::FORBIDDEN
            );
        }

        return redirect()->route('dashboard')
            ->with('error', 'Access denied. You do not have administrator privileges.');
    }
}
