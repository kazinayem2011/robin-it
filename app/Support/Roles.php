<?php

namespace App\Support;

use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Who in the shop may do what.
 *
 * There were two roles — admin and customer — so anyone let into the admin
 * could do everything in it: a storekeeper recording a delivery could also
 * read the profit and loss, change SMTP credentials, or delete a coupon.
 *
 * Abilities are named for sections of the admin rather than for individual
 * routes. Route-level permissions drift the moment someone adds an endpoint
 * and forgets the list; a section is something a person either does or does
 * not do as part of their job.
 */
class Roles
{
    public const OWNER = 'admin';          // kept as 'admin' so existing accounts are unaffected

    public const MANAGER = 'manager';

    public const STOREKEEPER = 'storekeeper';

    public const SUPPORT = 'support';

    public const ACCOUNTANT = 'accountant';

    public const CUSTOMER = 'customer';

    /** What each section covers, for the staff screen to explain itself. */
    public const ABILITIES = [
        'orders' => 'Orders, dispatch and returns',
        'refunds' => 'Refunds',
        'catalogue' => 'Products, categories and brands',
        'stock' => 'Stock, deliveries and suppliers',
        'customers' => 'Customer directory',
        'support' => 'Reviews and warranty claims',
        'marketing' => 'Banners, coupons and the tech journal',
        'couriers' => 'Delivery companies',
        'finance' => 'Expenses and profit & loss',
        'settings' => 'Site settings, showrooms and VAT',
        'staff' => 'Staff accounts and their roles',
    ];

    /**
     * The jobs a shop actually has.
     *
     * An owner is given every ability explicitly rather than being special-
     * cased, so adding a section means deciding who gets it rather than
     * quietly granting it to one role and nobody else.
     */
    public const DEFAULT_ROLES = [
        self::OWNER => [
            'label' => 'Owner',
            'description' => 'Everything, including staff and settings.',
            'abilities' => ['orders', 'refunds', 'catalogue', 'stock', 'customers',
                'support', 'marketing', 'couriers', 'finance', 'settings', 'staff'],
        ],
        self::MANAGER => [
            'label' => 'Manager',
            'description' => 'Runs the shop day to day. No staff accounts, no site settings.',
            'abilities' => ['orders', 'refunds', 'catalogue', 'stock', 'customers',
                'support', 'marketing', 'couriers', 'finance'],
        ],
        self::STOREKEEPER => [
            'label' => 'Storekeeper',
            'description' => 'Receives deliveries and keeps stock straight. No accounts, no settings.',
            'abilities' => ['stock', 'catalogue', 'orders'],
        ],
        self::SUPPORT => [
            'label' => 'Customer support',
            'description' => 'Answers customers: orders, returns, reviews and warranty.',
            'abilities' => ['orders', 'refunds', 'customers', 'support', 'couriers'],
        ],
        self::ACCOUNTANT => [
            'label' => 'Accountant',
            'description' => 'Expenses, refunds and the accounts. Cannot change the catalogue.',
            'abilities' => ['finance', 'refunds', 'orders', 'customers'],
        ],
    ];

    private const CACHE_KEY = 'roles.map';

    /**
     * Every role, as the shop has defined them.
     *
     * Read on nearly every request — the admin nav is drawn from a user's
     * abilities — so it is cached, and the cache holds a plain array. Objects
     * must never go in: config/cache.php sets serializable_classes to false,
     * and a cached model comes back as __PHP_Incomplete_Class on the first hit
     * in production while passing every test on the array driver.
     *
     * Deliberately no static memo on top. One would survive a queue worker's
     * whole lifetime, so a role changed in the admin would not reach the
     * worker until it restarted — and in the test suite it leaked one test's
     * roles into the next. The cache is shared and already fast enough.
     *
     * Falls back to the constant when the table is not there yet, so the app
     * boots during a fresh migration and the defaults are still the defaults.
     *
     * @return array<string, array{label:string, description:string, abilities:array}>
     */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            try {
                $rows = Role::ordered()->get(['key', 'label', 'description', 'abilities']);
            } catch (QueryException) {
                return self::DEFAULT_ROLES;
            }

            if ($rows->isEmpty()) {
                return self::DEFAULT_ROLES;
            }

            return $rows->mapWithKeys(fn (Role $r) => [$r->key => [
                'label' => $r->label,
                'description' => (string) $r->description,
                'abilities' => array_values((array) $r->abilities),
            ]])->all();
        });
    }

    /** Called whenever a role is saved or removed. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Roles that may sign into the admin at all. */
    public static function staffRoles(): array
    {
        return array_keys(array_filter(
            self::all(),
            fn ($role, $key) => $key !== self::CUSTOMER,
            ARRAY_FILTER_USE_BOTH
        ));
    }

    public static function isStaff(?string $role): bool
    {
        return $role !== null
            && $role !== self::CUSTOMER
            && array_key_exists($role, self::all());
    }

    /**
     * @return array<int, string>
     */
    public static function abilitiesFor(?string $role): array
    {
        if ($role === null || $role === self::CUSTOMER) {
            return [];
        }

        return self::all()[$role]['abilities'] ?? [];
    }

    public static function allows(?string $role, string $ability): bool
    {
        return in_array($ability, self::abilitiesFor($role), true);
    }

    public static function label(?string $role): string
    {
        return self::all()[$role]['label'] ?? ucfirst((string) $role);
    }

    /**
     * The roles list, for the staff form.
     *
     * @return array<int, array{value:string, label:string, description:string, abilities:array}>
     */
    public static function options(): array
    {
        return collect(self::all())
            ->reject(fn ($role, $key) => $key === self::CUSTOMER)
            ->map(fn ($role, $key) => [
                'value' => $key,
                'label' => $role['label'],
                'description' => $role['description'],
                'abilities' => array_map(
                    fn ($a) => self::ABILITIES[$a] ?? $a,
                    $role['abilities']
                ),
            ])
            ->values()
            ->all();
    }
}
