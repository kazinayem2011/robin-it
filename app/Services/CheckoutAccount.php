<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Helpers\PhoneHelper;
use App\Mail\WelcomeCustomerMail;
use App\Models\User;
use App\Support\BrandDetails;
use App\Support\Roles;
use App\Support\SmsTemplates;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * The account a guest's order belongs to, once they have proved their number.
 *
 * A guest used to leave nothing behind but a session id, so an order placed
 * without signing in could not be found again from any other device, and the
 * same person ordering twice was two strangers to the shop. Now the verified
 * number decides: an account with that number gets the order, and a number
 * with no account gets one.
 *
 * Only ever called after the checkout code has been checked. The number is what
 * signs the guest in, and a number taken on trust would hand anybody's account
 * to whoever typed it.
 */
class CheckoutAccount
{
    /**
     * Refuse an email that belongs to an account other than the one the order
     * is joining.
     *
     * Only the phone is proved at checkout, so an email can never sign anyone
     * in or link anything — and quietly accepting one that is somebody else's
     * went wrong both ways. A customer who signed up with only an email, then
     * checked out by phone, got a second account holding that phone, which
     * their real account could then never add. And whoever owned the address
     * was sent an order that was not in their account.
     *
     * Checked before the code is sent, so nobody spends one on an order this
     * would refuse, and again when the order is placed, in case the email
     * changed in between. It does tell the person typing that the address has
     * an account; the sign-up form has always told them the same.
     *
     * @param  User|null  $signedIn  the customer, when the order is theirs
     *                               already; otherwise the phone decides
     *
     * @throws ValidationException
     */
    public function assertEmailFits(string $phone, ?string $email, ?User $signedIn = null): void
    {
        $clash = $this->clash($phone, $email, $signedIn);

        if (! $clash) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => $clash['phone_account']
                ? 'This email belongs to a different account. Use the email on your account, or leave it blank.'
                : 'This email already has an account. Sign in to order with it, or leave the email blank.',
        ]);
    }

    /**
     * Whether the email belongs to an account other than the one the order
     * would join — and, if so, whether the phone has an account of its own.
     *
     * The checkout uses the answer to offer the guest a choice rather than a
     * dead end: sign in to the email's account with its password, or carry on
     * with the phone's (proved by a code), leaving the email off the order.
     *
     * @return array{email_account: true, phone_account: bool, phone_has_password: bool}|null
     */
    public function clash(string $phone, ?string $email, ?User $signedIn = null): ?array
    {
        $email = filled($email) ? strtolower(trim($email)) : null;

        if (! $email) {
            return null;
        }

        $emailOwner = User::whereRaw('LOWER(email) = ?', [$email])->first(['id']);

        if (! $emailOwner) {
            return null;
        }

        $orderOwner = $signedIn ?? $this->accountFor($phone);

        if ($orderOwner && $orderOwner->id === $emailOwner->id) {
            return null;
        }

        return [
            'email_account' => true,
            'phone_account' => $orderOwner !== null,
            // Decides what choosing the mobile asks for: its password, or a code.
            'phone_has_password' => (bool) $orderOwner?->hasPassword(),
        ];
    }

    /** The account holding this mobile number, if there is one. */
    public function accountFor(string $phone): ?User
    {
        return User::where('phone', PhoneHelper::normalizeBdPhone($phone) ?? $phone)->first();
    }

    /**
     * @throws StorefrontException when the number belongs to an account that
     *                             cannot be signed into this way
     */
    public function resolve(string $phone, string $name, ?string $email = null): User
    {
        $phone = PhoneHelper::normalizeBdPhone($phone) ?? $phone;
        $email = filled($email) ? strtolower(trim($email)) : null;

        $user = User::where('phone', $phone)->first();

        if ($user) {
            $this->assertCanSignIn($user);

            // They have just read a code off this handset, which is what
            // confirming a number means.
            $user->phone_verified_at ??= now();

            // Filled in, never replaced: the address on an account is the
            // customer's to change, from their own profile.
            if (! $user->email && $email && ! $this->emailTaken($email)) {
                $user->email = $email;
            }

            $user->save();

            return $user;
        }

        $user = new User;
        $user->fill([
            'name' => $name,
            // assertEmailFits() has already refused another account's
            // address; this only covers one registered in the meantime.
            'email' => $email && ! $this->emailTaken($email) ? $email : null,
            'phone' => $phone,
            /*
             * None yet. The customer is signed in by the code and stays signed
             * in for the shopper window; they set a password from their
             * profile, or through "Forgot password" by this same number, when
             * they want to sign in somewhere else. Null rather than a random
             * one nobody knows, so checkout can tell this account from one
             * that has a password to ask for.
             */
            'password' => null,
        ]);
        $user->phone_verified_at = now();
        $user->assignRole(User::ROLE_CUSTOMER)->save();

        $this->welcome($user);

        return $user;
    }

    /**
     * Tell somebody the shop has just made them an account.
     *
     * They came to buy something, not to register, and nothing said an account
     * now exists — so they would only find out by coming back to the site.
     *
     * No password is sent, here or anywhere: there is none to send, and one
     * sent by text or email is a password left lying in an inbox and a
     * gateway's logs. Both messages say where to set one instead.
     *
     * Best-effort, both of them: an order must not fail because a gateway or a
     * mail server is down.
     */
    private function welcome(User $user): void
    {
        try {
            if ($user->email) {
                Mail::to($user->email)->send(new WelcomeCustomerMail($user));
            }
        } catch (\Throwable $e) {
            Log::warning("Could not send the welcome email to {$user->email}: {$e->getMessage()}");
        }

        try {
            app(SmsService::class)->sendEvent(
                'account_created',
                $user->phone,
                SmsTemplates::accountCreated(BrandDetails::name())
            );
        } catch (\Throwable $e) {
            Log::warning("Could not send the account-created SMS to {$user->phone}: {$e->getMessage()}");
        }
    }

    /**
     * Staff sign in with a password, and a suspended account not at all.
     *
     * A staff account reached by a code typed at checkout would be the admin
     * panel opened by a text message. The customer-facing wording is the same
     * as the sign-in form's, so neither says more than that does.
     */
    private function assertCanSignIn(User $user): void
    {
        if (Roles::isStaff($user->role)) {
            throw new StorefrontException(
                'This number belongs to a staff account. Please sign in with your password to place an order.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        if ($user->is_active === false) {
            throw new StorefrontException(
                'This account has been suspended. Please contact us if you think that is a mistake.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }
    }

    private function emailTaken(string $email): bool
    {
        return User::whereRaw('LOWER(email) = ?', [$email])->exists();
    }
}
