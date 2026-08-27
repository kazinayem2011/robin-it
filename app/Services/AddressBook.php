<?php

namespace App\Services;

use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The addresses a customer has delivered to before.
 *
 * The address book and the checkout form never met. A customer could save
 * three addresses under their account and still be handed five empty boxes at
 * checkout, and whatever they typed there was thrown away the moment the order
 * was placed — so the next order started from nothing again. The Address model
 * had spoken of "the checkout address picker" since it was written; this is it.
 *
 * Only signed-in customers have a book. A guest has nowhere to keep one, and
 * inventing an account for them at checkout is not this class's business.
 */
class AddressBook
{
    /**
     * What the checkout form should open with.
     *
     * The default address if one is marked, otherwise the most recently saved —
     * which for a customer with one address is that address.
     */
    public static function forCheckout(?User $user): array
    {
        if (! $user) {
            return ['addresses' => [], 'contact' => null];
        }

        return [
            'addresses' => self::listFor($user),
            // Falls back to the account itself, so even a first-time buyer is
            // not asked for the name and number they registered with.
            'contact' => [
                'name' => $user->name,
                'phone' => $user->phone,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listFor(User $user): array
    {
        return Address::where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Address $a) => [
                'id' => $a->id,
                'is_default' => (bool) $a->is_default,
                // The book stores the street under `address`; checkout writes
                // `street_address`. Either may be the filled one.
                'name' => $a->name ?: $user->name,
                'phone' => $a->phone ?: $user->phone,
                'city' => $a->city,
                'zone' => $a->zone,
                'street_address' => $a->street_address ?: $a->address,
                'label' => $a->full_address,
            ])
            ->values()
            ->all();
    }

    /**
     * Keep the address an order was just delivered to.
     *
     * Called after the order is placed rather than before, so a checkout that
     * fails on stock or a bad coupon does not litter the book with addresses
     * for orders that never happened.
     *
     * An address the customer already has is not stored twice — they would
     * otherwise collect a duplicate for every repeat order to the same house.
     */
    public static function remember(?User $user, array $delivery): ?Address
    {
        if (! $user) {
            return null;
        }

        $street = trim((string) ($delivery['street_address'] ?? ''));
        $city = trim((string) ($delivery['city'] ?? ''));

        if ($street === '' || $city === '') {
            return null;
        }

        $zone = trim((string) ($delivery['zone'] ?? '')) ?: null;

        if ($existing = self::matching($user, $street, $city, $zone)) {
            return $existing;
        }

        $isFirst = Address::where('user_id', $user->id)->doesntExist();

        return DB::transaction(function () use ($user, $delivery, $street, $city, $zone, $isFirst) {
            if ($isFirst) {
                // Nothing to unset — this is the only one.
                return Address::create([
                    'user_id' => $user->id,
                    'name' => $delivery['name'] ?? $user->name,
                    'phone' => $delivery['phone'] ?? $user->phone,
                    'city' => $city,
                    'zone' => $zone,
                    'street_address' => $street,
                    // Mirrored so the address book, which renders `address`,
                    // shows the street rather than an empty line.
                    'address' => $street,
                    'is_default' => true,
                ]);
            }

            return Address::create([
                'user_id' => $user->id,
                'name' => $delivery['name'] ?? $user->name,
                'phone' => $delivery['phone'] ?? $user->phone,
                'city' => $city,
                'zone' => $zone,
                'street_address' => $street,
                'address' => $street,
                'is_default' => false,
            ]);
        });
    }

    /**
     * The same place, however it was spelled.
     *
     * Case and stray whitespace differ between one checkout and the next; the
     * house does not.
     */
    private static function matching(User $user, string $street, string $city, ?string $zone): ?Address
    {
        $norm = fn (?string $v) => preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $v)));

        return self::rawFor($user)->first(
            fn (Address $a) => $norm($a->street_address ?: $a->address) === $norm($street)
                && $norm($a->city) === $norm($city)
                && $norm($a->zone) === $norm($zone)
        );
    }

    /** @return Collection<int, Address> */
    private static function rawFor(User $user): Collection
    {
        return Address::where('user_id', $user->id)->get();
    }
}
