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

    /**
     * Several abilities means any one of them is enough.
     *
     * Uploading an image is the case that needs it: a product shot is
     * catalogue work and a banner is marketing, but they go through one
     * endpoint. Which folder a given member of staff may write to is settled
     * there, against the ability that folder belongs to.
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        foreach ($abilities as $ability) {
            if ($user?->can_($ability)) {
                return $next($request);
            }
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
