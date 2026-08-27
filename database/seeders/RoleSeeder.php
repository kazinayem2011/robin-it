<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\Roles;
use Illuminate\Database\Seeder;

/**
 * The jobs a shop starts with.
 *
 * firstOrCreate, never update: re-running a seeder must not undo a shop's own
 * decision about what a storekeeper may see.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;

        foreach (Roles::DEFAULT_ROLES as $key => $role) {
            Role::firstOrCreate(['key' => $key], [
                'label' => $role['label'],
                'description' => $role['description'],
                'abilities' => $role['abilities'],
                // The owner is every ability by definition; a shop that could
                // empty it could lock itself out of the screen that fills it.
                'is_system' => $key === Roles::OWNER,
                'sort_order' => $order += 10,
            ]);
        }

        Role::firstOrCreate(['key' => Roles::CUSTOMER], [
            'label' => 'Customer',
            'description' => 'Shops here. No part of the admin.',
            'abilities' => [],
            'is_system' => true,
            'sort_order' => 999,
        ]);

        Roles::forget();
    }
}
