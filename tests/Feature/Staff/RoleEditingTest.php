<?php

namespace Tests\Feature\Staff;

use App\Models\Role;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What each job covers, decided by the shop.
 *
 * The roles and their abilities were a constant, so a shop that wanted its
 * storekeepers to see the customer directory, or a role of its own for the
 * person who only answers the phone, needed a developer.
 */
class RoleEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Roles::forget();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => Roles::OWNER, 'is_active' => true]);
    }

    /** With no rows at all, the defaults are still the defaults. */
    public function test_the_constant_is_the_fallback(): void
    {
        Role::query()->delete();
        Roles::forget();

        $this->assertSame(
            Roles::DEFAULT_ROLES[Roles::STOREKEEPER]['abilities'],
            Roles::abilitiesFor(Roles::STOREKEEPER)
        );
    }

    public function test_an_ability_added_to_a_role_reaches_the_person_holding_it(): void
    {
        $keeper = User::factory()->create(['role' => Roles::STOREKEEPER, 'is_active' => true]);

        $this->assertFalse($keeper->can_('customers'));
        $this->actingAs($keeper)->get('/admin/customers')->assertRedirect(route('admin.dashboard'));

        $role = Role::where('key', Roles::STOREKEEPER)->sole();

        $this->actingAs($this->owner())
            ->patchJson("/api/admin/roles/{$role->id}", [
                'key' => $role->key,
                'label' => $role->label,
                'abilities' => [...$role->abilities, 'customers'],
            ])->assertSuccessful();

        $this->assertTrue($keeper->fresh()->can_('customers'));
        $this->actingAs($keeper->fresh())->get('/admin/customers')->assertStatus(200);
    }

    public function test_an_ability_taken_away_closes_the_door(): void
    {
        $keeper = User::factory()->create(['role' => Roles::STOREKEEPER, 'is_active' => true]);
        $this->actingAs($keeper)->get('/admin/stock')->assertStatus(200);

        $role = Role::where('key', Roles::STOREKEEPER)->sole();

        $this->actingAs($this->owner())
            ->patchJson("/api/admin/roles/{$role->id}", [
                'key' => $role->key,
                'label' => $role->label,
                'abilities' => ['catalogue'],
            ])->assertSuccessful();

        $this->actingAs($keeper->fresh())
            ->get('/admin/stock')
            ->assertRedirect(route('admin.dashboard'));
    }

    /**
     * The owner keeps everything.
     *
     * A shop that could take abilities off the owner could lock itself out of
     * the very screen that hands them back.
     */
    public function test_the_owner_cannot_be_stripped(): void
    {
        $role = Role::where('key', Roles::OWNER)->sole();

        $this->actingAs($this->owner())
            ->patchJson("/api/admin/roles/{$role->id}", [
                'key' => $role->key,
                'label' => 'Owner',
                'abilities' => ['orders'],
            ])->assertSuccessful();

        Roles::forget();
        $this->assertSame(array_keys(Roles::ABILITIES), $role->fresh()->abilities);
        $this->assertTrue(Roles::allows(Roles::OWNER, 'staff'));
        $this->assertTrue(Roles::allows(Roles::OWNER, 'settings'));
    }

    public function test_a_built_in_role_keeps_its_key(): void
    {
        $role = Role::where('key', Roles::OWNER)->sole();

        $this->actingAs($this->owner())
            ->patchJson("/api/admin/roles/{$role->id}", [
                'key' => 'boss',
                'label' => 'Boss',
                'abilities' => [],
            ])->assertSuccessful();

        // Accounts store the key; moving it would orphan everyone holding it.
        $this->assertSame(Roles::OWNER, $role->fresh()->key);
        $this->assertSame('Boss', $role->fresh()->label);
    }

    public function test_a_shop_can_add_a_role_of_its_own(): void
    {
        $this->actingAs($this->owner())
            ->postJson('/api/admin/roles', [
                'key' => 'shift_lead',
                'label' => 'Shift lead',
                'description' => 'Runs the floor on evenings.',
                'abilities' => ['orders', 'stock'],
            ])->assertStatus(201);

        Roles::forget();

        $this->assertContains('shift_lead', Roles::staffRoles());
        $this->assertSame(['orders', 'stock'], Roles::abilitiesFor('shift_lead'));

        $lead = User::factory()->create(['role' => 'shift_lead', 'is_active' => true]);
        $this->assertTrue($lead->isAdmin());
        $this->actingAs($lead)->get('/admin/stock')->assertStatus(200);
        $this->actingAs($lead)->get('/admin/settings')->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_role_people_hold_cannot_be_removed(): void
    {
        $this->actingAs($this->owner())->postJson('/api/admin/roles', [
            'key' => 'shift_lead', 'label' => 'Shift lead', 'abilities' => ['orders'],
        ])->assertStatus(201);

        $role = Role::where('key', 'shift_lead')->sole();
        User::factory()->create(['role' => 'shift_lead']);

        // Otherwise they are signed in holding a role nothing recognises.
        $this->actingAs($this->owner())
            ->deleteJson("/api/admin/roles/{$role->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('roles', ['key' => 'shift_lead']);
    }

    public function test_a_built_in_role_cannot_be_removed(): void
    {
        $role = Role::where('key', Roles::OWNER)->sole();

        $this->actingAs($this->owner())
            ->deleteJson("/api/admin/roles/{$role->id}")
            ->assertStatus(422);
    }

    public function test_only_someone_who_manages_staff_may_redraw_the_lines(): void
    {
        $role = Role::where('key', Roles::STOREKEEPER)->sole();
        $manager = User::factory()->create(['role' => Roles::MANAGER, 'is_active' => true]);

        $this->actingAs($manager)->get('/admin/roles')->assertRedirect(route('admin.dashboard'));

        // A manager giving themselves settings would be the whole point of the
        // ability, undone.
        $this->actingAs($manager)
            ->patchJson("/api/admin/roles/{$role->id}", [
                'key' => $role->key, 'label' => $role->label, 'abilities' => ['settings'],
            ])->assertStatus(403);
    }

    public function test_an_unknown_ability_is_refused(): void
    {
        $role = Role::where('key', Roles::STOREKEEPER)->sole();

        $this->actingAs($this->owner())
            ->patchJson("/api/admin/roles/{$role->id}", [
                'key' => $role->key,
                'label' => $role->label,
                'abilities' => ['stock', 'everything'],
            ])->assertStatus(422);
    }

    /** Re-running the seeder must not undo the shop's own decisions. */
    public function test_seeding_again_keeps_what_the_shop_chose(): void
    {
        Role::where('key', Roles::STOREKEEPER)->update(['abilities' => ['stock']]);
        Roles::forget();

        $this->seed(RoleSeeder::class);
        Roles::forget();

        $this->assertSame(['stock'], Roles::abilitiesFor(Roles::STOREKEEPER));
    }
}
