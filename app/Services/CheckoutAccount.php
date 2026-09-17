<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Helpers\PhoneHelper;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
            // Somebody else's address is left off rather than refused: the
            // order still goes through, and the email for it still goes to
            // what they typed.
            'email' => $email && ! $this->emailTaken($email) ? $email : null,
            'phone' => $phone,
            /*
             * Nobody knows it, on purpose. The customer is signed in by the
             * code, stays signed in for the shopper window, and sets a password
             * of their own through "Forgot password" — which goes by this same
             * number — when they want to sign in somewhere else.
             */
            'password' => Hash::make(Str::random(40)),
        ]);
        $user->phone_verified_at = now();
        $user->assignRole(User::ROLE_CUSTOMER)->save();

        return $user;
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
