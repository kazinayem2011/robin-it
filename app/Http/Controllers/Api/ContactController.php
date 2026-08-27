<?php

namespace App\Http\Controllers\Api;

use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Services\ContactService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the Contact page and the footer's newsletter box post to.
 *
 * Both were links and a form with no handler behind them.
 */
class ContactController extends Controller
{
    public function __construct(
        private readonly ContactService $contact,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function store(Request $request): JsonResponse
    {
        PhoneHelper::canonicalise($request, 'phone');

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:180',
            'phone' => ['nullable', 'string', 'max:20', PhoneHelper::RULE],
            'subject' => 'required|string|max:160',
            'message' => 'required|string|min:10|max:4000',
        ], [
            'message.min' => 'Please say a little more so we can help.',
            'phone.regex' => 'Please enter a valid 11-digit Bangladeshi mobile number, or leave it blank.',
        ]);

        $message = $this->contact->record($validated, $request->ip());

        return $this->successResponse(
            ['reference' => $message->id],
            "Thanks {$message->name} — we have your message and will reply to {$message->email}.",
            201
        );
    }

    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:180',
            'name' => 'nullable|string|max:120',
            'source' => 'nullable|string|max:40',
        ]);

        $this->subscriptions->subscribe(
            $validated['email'],
            $validated['name'] ?? null,
            $validated['source'] ?? 'footer'
        );

        /*
         * The same answer whether or not the address was already on the list.
         * A visitor cannot see the list, so "you are already subscribed" would
         * tell anyone with a guess who is on it.
         */
        return $this->successResponse(
            [],
            "You're on the list. We'll email you when something good lands."
        );
    }
}
