<?php

namespace App\Http\Controllers\Api;

use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Services\ContactService;
use App\Services\SmsService;
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

        /*
         * An address is not demanded of everybody any more.
         *
         * It used to be, which left a customer who registered with a mobile
         * number and has no address two ways out, both wrong: invent one the
         * shop cannot reach them at, or type somebody else's — which is how an
         * answer ends up in a stranger's inbox.
         *
         * Signed in, neither is needed: the answer appears in their own
         * messages and rings their bell. A guest leaves a mobile number, and
         * an address too if they like: most customers here reach the shop by
         * phone, and a number is one the shop can ring or text back the same
         * day. It was "either one", which let a guest leave only an address
         * nobody checks for a reply.
         */
        $guest = $request->user() === null;

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['nullable', 'email', 'max:180'],
            'phone' => [
                $guest ? 'required' : 'nullable',
                'string', 'max:20', PhoneHelper::RULE,
            ],
            'subject' => 'required|string|max:160',
            'message' => 'required|string|min:10|max:4000',
        ], [
            'message.min' => 'Please say a little more so we can help.',
            'phone.regex' => 'Please enter a valid 11-digit mobile number, such as 01711223344.',
            'phone.required' => 'Leave us a mobile number, so we can reply.',
        ]);

        $message = $this->contact->record($validated, $request->ip(), $request->user());

        return $this->successResponse(
            ['reference' => $message->id],
            $this->promise($message, $request->user() !== null),
            201
        );
    }

    /**
     * Where the answer will turn up, in the words of whoever asked.
     *
     * Signed in, the thread is theirs and the bell rings whether or not they
     * left an address; a guest has only what they typed.
     */
    private function promise(ContactMessage $message, bool $signedIn): string
    {
        $where = match (true) {
            filled($message->email) => "will reply to {$message->email}",
            $signedIn => 'will reply in your messages',
            // Texted, where the shop can: promising a phone call and then
            // sending a text is a small lie, and the other way round is worse.
            app(SmsService::class)->sends('contact_reply') => "will text you on {$message->phone}",
            default => "will call you on {$message->phone}",
        };

        return "Thanks {$message->name} — we have your message and {$where}.";
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
