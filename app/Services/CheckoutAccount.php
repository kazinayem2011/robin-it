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
    /** The account this request generated a password for, if any. */
    private ?int $issuedTo = null;

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

            /*
             * An account checkout made before passwords were generated has
             * none, and its owner has just proved the number — so it gets one
             * now, sent the same way as a new account's. An account that has
             * a password keeps it: that is the customer's, and never changed
             * here.
             */
            $password = $user->hasPassword() ? null : self::generatePassword();

            if ($password !== null) {
                $user->password = $password;
            }

            $user->save();

            if ($password !== null) {
                $this->issuedTo = $user->id;
                $this->welcome($user, $password);
            }

            return $user;
        }

        /*
         * A password, generated here and sent to the customer.
         *
         * The shop's decision: customers always sign in with a password. An
         * account checkout made used to have none, so once the session ended
         * its owner had no way back in but a reset for a password they had
         * never chosen. Sent by text to the number just proved with a code,
         * and by email if they gave one; they can change it from their profile.
         */
        $password = self::generatePassword();

        $user = new User;
        $user->fill([
            'name' => $name,
            // assertEmailFits() has already refused another account's
            // address; this only covers one registered in the meantime.
            'email' => $email && ! $this->emailTaken($email) ? $email : null,
            'phone' => $phone,
            // Hashed by the model's cast; the plain one lives only in the two
            // messages below.
            'password' => $password,
        ]);
        $user->phone_verified_at = now();
        $user->assignRole(User::ROLE_CUSTOMER)->save();

        $this->issuedTo = $user->id;
        $this->welcome($user, $password);

        return $user;
    }

    /**
     * Whether resolve() just gave this account its password — which is what
     * the order confirmation page says an account was made by.
     */
    public function issuedPasswordTo(?User $user): bool
    {
        return $user !== null && $this->issuedTo === $user->id;
    }

    /**
     * Eight characters somebody can read off a phone and type.
     *
     * Letters and digits without the ones that look alike — 0 and O, 1, l and
     * I — since this is copied by eye from a text message, often onto another
     * device. At least one letter and one digit, so it reads as a password
     * rather than a word or a number. random_int, because a predictable
     * generator makes the password a formality: 55 characters to the eighth
     * power is plenty for something the login form limits to five tries.
     */
    public static function generatePassword(int $length = 8): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $last = strlen($alphabet) - 1;

        do {
            $password = '';

            for ($i = 0; $i < $length; $i++) {
                $password .= $alphabet[random_int(0, $last)];
            }
        } while (! preg_match('/\d/', $password) || ! preg_match('/[a-zA-Z]/', $password));

        return $password;
    }

    /**
     * Tell somebody the shop has made them an account, and how to get into it.
     *
     * They came to buy something, not to register, and nothing said an account
     * now exists — so they would only find out by coming back to the site.
     *
     * Both messages carry the password. It is never written anywhere else:
     * not logged, not returned to the page, hashed on the account. The one
     * exception is the local log-only SMS fallback, which records every text
     * body for a developer without a gateway and is never on in production.
     *
     * The text goes even with "Account created" switched off in Settings when
     * there is no email to carry the password instead — otherwise the switch
     * would leave a customer with an account and no way into it.
     *
     * Best-effort, both of them: an order must not fail because a gateway or a
     * mail server is down.
     */
    private function welcome(User $user, string $password): void
    {
        try {
            if ($user->email) {
                Mail::to($user->email)->send(new WelcomeCustomerMail($user, $password));
            }
        } catch (\Throwable $e) {
            Log::warning("Could not send the welcome email to {$user->email}.");
        }

        try {
            $sms = app(SmsService::class);
            $text = SmsTemplates::accountCreated(BrandDetails::name(), $password);

            $user->email
                ? $sms->sendEvent('account_created', $user->phone, $text)
                : $sms->send($user->phone, $text);
        } catch (\Throwable $e) {
            Log::warning("Could not send the account-created SMS to {$user->phone}.");
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
