<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leaving the mailing list, from a link in an email.
 *
 * No account and no form: someone who wants out should be out by the time the
 * page has loaded. The token is what identifies them — the address alone must
 * not be, or anyone could unsubscribe anyone.
 */
class UnsubscribeController extends Controller
{
    public function __invoke(string $token, SubscriptionService $subscriptions): Response
    {
        $subscriber = $subscriptions->unsubscribeByToken($token);

        return Inertia::render('Unsubscribe/Index', [
            // A stale link and a wrong one look the same, and neither says
            // whether that address was ever on the list.
            'done' => $subscriber !== null,
            'email' => $subscriber?->email,
        ]);
    }
}
