<?php

namespace Tests\Feature\Staff;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * There were two roles — admin and customer — so anyone let into the admin
 * could do everything in it. A storekeeper recording a delivery could read the
 * profit and loss, change the SMTP password, or delete a coupon.
 */
class StaffRoleTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->forceFill(['is_active' => true])->save();

        return $user->fresh();
    }

    /** An existing admin keeps everything. Nothing about them changed. */
    public function test_an_existing_admin_still_has_every_ability(): void
    {
        $admin = $this->staff(Roles::OWNER);

        foreach (array_keys(Roles::ABILITIES) as $ability) {
            $this->assertTrue($admin->can_($ability), "An owner lost '{$ability}'.");
        }
    }

    /** @return array<string, array{0:string, 1:string, 2:bool}> */
    public static function abilityProvider(): array
    {
        return [
            // A storekeeper receives deliveries and sees no money.
            'storekeeper: stock' => [Roles::STOREKEEPER, '/admin/stock', true],
            'storekeeper: products' => [Roles::STOREKEEPER, '/admin/products', true],
            'storekeeper: profit & loss' => [Roles::STOREKEEPER, '/admin/reports/profit-loss', false],
            'storekeeper: expenses' => [Roles::STOREKEEPER, '/admin/expenses', false],
            'storekeeper: settings' => [Roles::STOREKEEPER, '/admin/settings', false],
            'storekeeper: staff' => [Roles::STOREKEEPER, '/admin/staff', false],

            // Support answers customers; it does not run the catalogue.
            'support: orders' => [Roles::SUPPORT, '/admin/orders', true],
            'support: reviews' => [Roles::SUPPORT, '/admin/reviews', true],
            'support: refunds' => [Roles::SUPPORT, '/admin/refunds', true],
            'support: products' => [Roles::SUPPORT, '/admin/products', false],
            'support: profit & loss' => [Roles::SUPPORT, '/admin/reports/profit-loss', false],

            // An accountant reads the money and cannot change the catalogue.
            'accountant: profit & loss' => [Roles::ACCOUNTANT, '/admin/reports/profit-loss', true],
            'accountant: expenses' => [Roles::ACCOUNTANT, '/admin/expenses', true],
            'accountant: stock' => [Roles::ACCOUNTANT, '/admin/stock', false],
            'accountant: settings' => [Roles::ACCOUNTANT, '/admin/settings', false],

            // A manager runs the shop but not the staff list or the settings.
            'manager: expenses' => [Roles::MANAGER, '/admin/expenses', true],
            'manager: coupons' => [Roles::MANAGER, '/admin/coupons', true],
            'manager: settings' => [Roles::MANAGER, '/admin/settings', false],
            'manager: staff' => [Roles::MANAGER, '/admin/staff', false],
        ];
    }

    #[DataProvider('abilityProvider')]
    public function test_a_role_reaches_only_its_own_sections(string $role, string $uri, bool $allowed): void
    {
        $response = $this->actingAs($this->staff($role))->get($uri);

        if ($allowed) {
            $response->assertStatus(200);
        } else {
            // Sent back to the dashboard rather than shown a wall.
            $response->assertRedirect('/admin/dashboard');
        }
    }

    /** The API side is gated too, not just the pages. */
    public function test_a_storekeeper_cannot_write_where_they_cannot_read(): void
    {
        $storekeeper = $this->staff(Roles::STOREKEEPER);

        $this->actingAs($storekeeper)
            ->postJson('/api/admin/expenses', [
                'expense_category_id' => 1, 'amount' => 100,
                'description' => 'x', 'incurred_on' => now()->toDateString(),
            ])->assertStatus(403);

        $this->actingAs($storekeeper)
            ->postJson('/api/admin/settings', ['settings' => ['site_name' => 'Hacked']])
            ->assertStatus(403);
    }

    /** But they can do their own job. */
    public function test_a_storekeeper_can_still_receive_a_delivery(): void
    {
        $this->actingAs($this->staff(Roles::STOREKEEPER))
            ->getJson('/api/admin/stock/units')
            ->assertStatus(200);
    }

    public function test_a_suspended_account_cannot_reach_the_admin_at_all(): void
    {
        $manager = $this->staff(Roles::MANAGER);
        $manager->forceFill(['is_active' => false])->save();

        $this->assertFalse($manager->fresh()->isAdmin());
        $this->actingAs($manager->fresh())->get('/admin/orders')->assertRedirect();
    }

    public function test_a_customer_still_reaches_nothing(): void
    {
        $customer = User::factory()->create(['role' => Roles::CUSTOMER]);

        $this->assertFalse($customer->isAdmin());
        $this->assertSame([], $customer->abilities());
        $this->actingAs($customer)->get('/admin/orders')->assertRedirect();
    }

    // ── Managing staff ──────────────────────────────────────────────────────

    public function test_an_owner_can_create_a_member_of_staff(): void
    {
        $this->actingAs($this->staff(Roles::OWNER))
            ->postJson('/api/admin/staff', [
                'name' => 'Karim',
                'email' => 'karim@example.com',
                'role' => Roles::STOREKEEPER,
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])->assertStatus(201);

        $karim = User::where('email', 'karim@example.com')->first();

        $this->assertSame(Roles::STOREKEEPER, $karim->role);
        $this->assertTrue($karim->isAdmin());
        $this->assertFalse($karim->can_('finance'));
    }

    public function test_only_an_owner_may_manage_staff(): void
    {
        foreach ([Roles::MANAGER, Roles::STOREKEEPER, Roles::SUPPORT, Roles::ACCOUNTANT] as $role) {
            $this->actingAs($this->staff($role))
                ->postJson('/api/admin/staff', [
                    'name' => 'Sneaky', 'email' => "s{$role}@example.com",
                    'role' => Roles::OWNER,
                    'password' => 'Password123!', 'password_confirmation' => 'Password123!',
                ])->assertStatus(403);
        }

        $this->assertSame(0, User::where('name', 'Sneaky')->count());
    }

    /** Roles are assigned explicitly, never from request input. */
    public function test_a_staff_member_cannot_promote_themselves_through_their_profile(): void
    {
        $storekeeper = $this->staff(Roles::STOREKEEPER);

        $this->actingAs($storekeeper)->post('/account/profile', [
            'name' => 'Karim', 'email' => 'karim@example.com',
            'phone' => '01712345678', 'role' => Roles::OWNER,
        ]);

        $this->assertSame(Roles::STOREKEEPER, $storekeeper->fresh()->role);
    }

    /**
     * A shop with no owner has nobody who can appoint one, which needs a
     * developer and a database console to undo.
     */
    public function test_the_last_owner_cannot_demote_themselves(): void
    {
        $owner = $this->staff(Roles::OWNER);

        $this->actingAs($owner)
            ->patchJson("/api/admin/staff/{$owner->id}", [
                'name' => $owner->name, 'email' => $owner->email,
                'role' => Roles::MANAGER,
            ])->assertStatus(422);

        $this->assertSame(Roles::OWNER, $owner->fresh()->role);
    }

    public function test_the_last_owner_cannot_suspend_themselves(): void
    {
        $owner = $this->staff(Roles::OWNER);

        $this->actingAs($owner)
            ->deleteJson("/api/admin/staff/{$owner->id}")
            ->assertStatus(422);

        $this->assertTrue($owner->fresh()->is_active);
    }

    /** With a second owner in place, stepping back is fine. */
    public function test_an_owner_can_step_back_once_someone_else_can_take_over(): void
    {
        $owner = $this->staff(Roles::OWNER);
        $this->staff(Roles::OWNER);

        $this->actingAs($owner)
            ->patchJson("/api/admin/staff/{$owner->id}", [
                'name' => $owner->name, 'email' => $owner->email,
                'role' => Roles::MANAGER,
            ])->assertStatus(200);

        $this->assertSame(Roles::MANAGER, $owner->fresh()->role);
    }

    /**
     * Suspended, not deleted: their name is on deliveries, adjustments and
     * refunds going back years.
     */
    public function test_suspending_keeps_the_account(): void
    {
        $owner = $this->staff(Roles::OWNER);
        $karim = $this->staff(Roles::STOREKEEPER);

        $this->actingAs($owner)->deleteJson("/api/admin/staff/{$karim->id}")->assertStatus(200);

        $karim->refresh();

        $this->assertNotNull($karim->id);
        $this->assertFalse($karim->is_active);
        $this->assertFalse($karim->isAdmin());
    }

    /** A blank password on edit means "leave it as it is". */
    public function test_editing_without_a_password_keeps_the_old_one(): void
    {
        $owner = $this->staff(Roles::OWNER);
        $karim = $this->staff(Roles::STOREKEEPER);
        $before = $karim->password;

        $this->actingAs($owner)
            ->patchJson("/api/admin/staff/{$karim->id}", [
                'name' => 'Karim Uddin', 'email' => $karim->email,
                'role' => Roles::SUPPORT,
            ])->assertStatus(200);

        $karim->refresh();

        $this->assertSame($before, $karim->password);
        $this->assertSame('Karim Uddin', $karim->name);
        $this->assertSame(Roles::SUPPORT, $karim->role);
    }

    /** The nav is drawn from these, so nobody is shown a door they cannot open. */
    public function test_abilities_reach_the_frontend(): void
    {
        $props = $this->actingAs($this->staff(Roles::STOREKEEPER))
            ->get('/admin/dashboard')
            ->assertStatus(200)
            ->viewData('page')['props'];

        $abilities = $props['auth']['user']['abilities'];

        $this->assertContains('stock', $abilities);
        $this->assertNotContains('finance', $abilities);
        $this->assertNotContains('staff', $abilities);
    }

    /**
     * Hiding the cards is not enough.
     *
     * Inertia ships every prop in the page source, so a storekeeper who opened
     * the page source would read the shop's takings whatever the dashboard
     * chose to draw. The numbers must not be sent at all.
     */
    public function test_the_dashboard_withholds_the_takings_from_a_storekeeper(): void
    {
        $props = $this->actingAs($this->staff(Roles::STOREKEEPER))
            ->get('/admin/dashboard')
            ->assertStatus(200)
            ->viewData('page')['props'];

        $this->assertNull($props['metrics']['total_revenue']);
        $this->assertNull($props['metrics']['total_customers']);
        $this->assertNull($props['margin']);

        // The stock figures are their job and stay.
        $this->assertNotNull($props['metrics']['low_stock_count']);
    }

    public function test_the_dashboard_still_shows_the_takings_to_an_owner(): void
    {
        $props = $this->actingAs($this->staff(Roles::OWNER))
            ->get('/admin/dashboard')
            ->assertStatus(200)
            ->viewData('page')['props'];

        $this->assertNotNull($props['metrics']['total_revenue']);
        $this->assertNotNull($props['margin']);
    }

    /** An accountant keeps the books but does not touch the catalogue. */
    public function test_an_accountant_sees_the_takings_but_not_the_catalogue(): void
    {
        $accountant = $this->staff(Roles::ACCOUNTANT);

        $props = $this->actingAs($accountant)
            ->get('/admin/dashboard')
            ->assertStatus(200)
            ->viewData('page')['props'];

        $this->assertNotNull($props['metrics']['total_revenue']);

        $this->actingAs($accountant)
            ->get('/admin/products')
            ->assertRedirect(route('admin.dashboard'));
    }
}
