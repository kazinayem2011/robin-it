<?php

namespace App\Http\Middleware;

use App\Enums\ApiCode;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a section of the admin behind an ability.
 *
 * `admin` already established that someone is staff. This decides whether this
 * particular member of staff does this particular job — a storekeeper receives
 * deliveries and does not read the accounts.
 *
 * Applied per route group, so a section added later is unreachable until
 * someone names its ability, rather than being open by default.
 */
class EnsureAbility
{
    use ApiResponse;

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        if ($request->user()?->can_($ability)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->errorResponse(
                'Your role does not cover this part of the admin.',
                403,
                ApiCode::FORBIDDEN
            );
        }

        return redirect()->route('admin.dashboard')
            ->with('error', 'Your role does not cover that part of the admin.');
    }
}
