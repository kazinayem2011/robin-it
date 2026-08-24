<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Becoming an admin.
 *
 * The most valuable thing an attacker can do here is promote themselves, so
 * every route that writes to a user is checked for it.
 */
class PrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_cannot_grant_the_admin_role(): void
    {
        $this->post('/register', [
            'name' => 'Opportunist',
            'email' => 'opportunist@example.com',
            'phone' => '01712345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            // Not a field on the form; the question is whether the model
            // accepts it anyway.
            'role' => 'admin',
        ]);

        $user = User::where('email', 'opportunist@example.com')->first();

        $this->assertNotNull($user, 'registration failed, so this proves nothing');
        $this->assertNotSame('admin', $user->role, 'a visitor made themselves an admin');
    }

    public function test_updating_a_profile_cannot_grant_the_admin_role(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user)->patch('/profile', [
            'name' => 'Still A Customer',
            'email' => $user->email,
            'role' => 'admin',
        ]);

        $this->assertSame('customer', $user->fresh()->role);
    }

    public function test_the_account_profile_endpoint_cannot_grant_the_admin_role(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user)->post('/account/profile', [
            'name' => 'Still A Customer',
            'phone' => '01712345678',
            'role' => 'admin',
        ]);

        $this->assertSame('customer', $user->fresh()->role);
    }

    /** Shared props go to every page, so anything in them is public. */
    public function test_the_password_hash_never_reaches_the_browser(): void
    {
        $user = User::factory()->create();

        $props = $this->actingAs($user)->get('/')->viewData('page')['props'];

        $encoded = json_encode($props);

        $this->assertStringNotContainsString('password', strtolower($encoded));
        $this->assertStringNotContainsString('remember_token', $encoded);
        $this->assertArrayNotHasKey('password', $props['auth']['user']);
    }

    public function test_a_customer_cannot_read_the_admin_dashboard(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->get('/admin/dashboard');

        $this->assertContains($response->status(), [302, 403]);
    }

    public function test_a_guest_cannot_read_the_admin_dashboard(): void
    {
        $response = $this->get('/admin/dashboard');

        $this->assertContains($response->status(), [302, 403]);
    }
}
