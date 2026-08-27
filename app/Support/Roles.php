<?php

namespace App\Support;

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
    public const ROLES = [
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

    /** Roles that may sign into the admin at all. */
    public static function staffRoles(): array
    {
        return array_keys(self::ROLES);
    }

    public static function isStaff(?string $role): bool
    {
        return $role !== null && array_key_exists($role, self::ROLES);
    }

    /**
     * @return array<int, string>
     */
    public static function abilitiesFor(?string $role): array
    {
        return self::ROLES[$role]['abilities'] ?? [];
    }

    public static function allows(?string $role, string $ability): bool
    {
        return in_array($ability, self::abilitiesFor($role), true);
    }

    public static function label(?string $role): string
    {
        return self::ROLES[$role]['label'] ?? ucfirst((string) $role);
    }

    /**
     * The roles list, for the staff form.
     *
     * @return array<int, array{value:string, label:string, description:string, abilities:array}>
     */
    public static function options(): array
    {
        return collect(self::ROLES)
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
