<?php

namespace App\Services;

use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The mailing list.
 *
 * The footer's newsletter box was a form whose only handler was
 * onSubmit={(e) => e.preventDefault()}, so an address typed into it went
 * nowhere and the visitor got no sign either way.
 */
class SubscriptionService
{
    /**
     * Add an address, or bring one back that had left.
     *
     * Signing up twice is not an error to show a visitor — they cannot see the
     * list, so being told "you are already on it" reveals who is on it. The
     * same reassurance is given either way, and the row is not duplicated.
     */
    public function subscribe(string $email, ?string $name = null, ?string $source = null): Subscriber
    {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($email, $name, $source) {
            $subscriber = Subscriber::where('email', $email)->lockForUpdate()->first();

            if (! $subscriber) {
                return Subscriber::create([
                    'email' => $email,
                    'name' => $name,
                    'status' => Subscriber::SUBSCRIBED,
                    'token' => Subscriber::newToken(),
                    'source' => $source,
                    'subscribed_at' => now(),
                ]);
            }

            // Coming back after leaving: a fresh token, so a link from the old
            // emails cannot take them off again.
            if (! $subscriber->isSubscribed()) {
                $subscriber->forceFill([
                    'status' => Subscriber::SUBSCRIBED,
                    'token' => Subscriber::newToken(),
                    'subscribed_at' => now(),
                    'unsubscribed_at' => null,
                ])->save();
            }

            if ($name && ! $subscriber->name) {
                $subscriber->forceFill(['name' => $name])->save();
            }

            return $subscriber;
        });
    }

    /**
     * Take someone off the list.
     *
     * The row stays: a record that they asked to be removed is what stops them
     * being added again by the next import, and it is what proves the request
     * was honoured.
     */
    public function unsubscribeByToken(string $token): ?Subscriber
    {
        $subscriber = Subscriber::where('token', $token)->first();

        if (! $subscriber) {
            return null;
        }

        if ($subscriber->isSubscribed()) {
            $subscriber->forceFill([
                'status' => Subscriber::UNSUBSCRIBED,
                'unsubscribed_at' => now(),
            ])->save();
        }

        /*
         * And off the customer list, if that address has an account.
         *
         * Leaving once has to mean leaving everywhere. Otherwise somebody who
         * clicks unsubscribe keeps receiving the shop's marketing because they
         * are also a customer, which reads as the link not working — and the
         * next thing they press is the spam button, which costs the shop its
         * deliverability for everybody still on the list.
         *
         * Order confirmations and dispatch notes are untouched: those are
         * things the customer asked for.
         */
        User::where('email', $subscriber->email)
            ->where('role', User::ROLE_CUSTOMER)
            ->update(['accepts_marketing' => false]);

        return $subscriber;
    }
}
