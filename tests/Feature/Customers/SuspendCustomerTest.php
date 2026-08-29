<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Closing the door on a customer.
 *
 * Staff have had a suspend since roles were introduced and customers never did,
 * so the only way to stop somebody ordering — a fraudulent account, a
 * chargeback, somebody refusing every cash-on-delivery parcel — was to delete
 * them, which takes their order history with it.
 *
 * Worse, is_active already existed on every user and was only ever consulted
 * for staff. Setting it on a customer stopped nothing at all.
 */
class SuspendCustomerTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_CUSTOMER,
            'email' => 'rahim@example.com',
            'password' => Hash::make('Str0ng-Passw0rd!'),
            'is_active' => true,
        ], $overrides));
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // --- the flag ------------------------------------------------------------

    public function test_an_owner_can_suspend_and_restore_a_customer(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();

        $this->actingAs($owner)
            ->putJson("/api/admin/customers/{$customer->id}/active", ['is_active' => false])
            ->assertOk();

        $this->assertFalse($customer->fresh()->is_active);

        $this->actingAs($owner)
            ->putJson("/api/admin/customers/{$customer->id}/active", ['is_active' => true])
            ->assertOk();

        $this->assertTrue($customer->fresh()->is_active);
    }

    /** Suspending keeps the account and everything attached to it. */
    public function test_suspending_does_not_delete_anything(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->owner())
            ->putJson("/api/admin/customers/{$customer->id}/active", ['is_active' => false]);

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'email' => 'rahim@example.com']);
    }

    public function test_this_endpoint_is_for_customers_not_staff(): void
    {
        $staff = User::factory()->create(['role' => 'manager']);

        $this->actingAs($this->owner())
            ->putJson("/api/admin/customers/{$staff->id}/active", ['is_active' => false])
            ->assertStatus(404);

        $this->assertNotFalse($staff->fresh()->is_active);
    }

    public function test_suspending_needs_the_customers_ability(): void
    {
        $customer = $this->customer();

        $this->actingAs(User::factory()->create(['role' => 'storekeeper']))
            ->putJson("/api/admin/customers/{$customer->id}/active", ['is_active' => false])
            ->assertStatus(403);
    }

    // --- and what the flag now actually does ---------------------------------

    /**
     * The part that was missing. is_active was consulted only by isAdmin(), so
     * a suspended customer could still sign in and order — the flag was a note
     * to staff rather than a lock on the door.
     */
    public function test_a_suspended_customer_cannot_sign_in(): void
    {
        $this->customer(['is_active' => false]);

        $this->post('/login', [
            'login' => 'rahim@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_the_refusal_names_suspension_rather_than_a_wrong_password(): void
    {
        $this->customer(['is_active' => false]);

        $this->post('/login', [
            'login' => 'rahim@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ])->assertSessionHasErrorsIn('default', ['login' => 'This account has been suspended. Please contact us if you think that is a mistake.']);
    }

    /**
     * The wrong password on a suspended account still reads as a wrong
     * password. Answering "suspended" before checking it would tell anybody
     * holding a list of addresses which ones have accounts here.
     */
    public function test_a_wrong_password_gives_nothing_away(): void
    {
        $this->customer(['is_active' => false]);

        $this->post('/login', [
            'login' => 'rahim@example.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors(['login' => 'Invalid email/mobile number or password.']);
    }

    public function test_an_active_customer_signs_in_as_before(): void
    {
        $customer = $this->customer();

        $this->post('/login', [
            'login' => 'rahim@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($customer);
    }

    /**
     * A suspended account must not keep the session it already had, or the
     * suspension does nothing until it expires — which is the entire window
     * that matters for the abuse this exists to stop.
     */
    public function test_suspending_signs_them_out_of_the_session_they_are_already_in(): void
    {
        $customer = $this->customer();

        $this->post('/login', ['login' => 'rahim@example.com', 'password' => 'Str0ng-Passw0rd!']);
        $this->assertAuthenticatedAs($customer);

        // Their remember-me handle is cut loose along with the session rows.
        $was = $customer->fresh()->remember_token;

        $this->actingAs($this->owner())
            ->putJson("/api/admin/customers/{$customer->id}/active", ['is_active' => false])
            ->assertOk();

        $this->assertNotSame($was, $customer->fresh()->remember_token);
    }

    /** Staff suspension keeps working exactly as it did. */
    public function test_a_suspended_staff_member_still_cannot_reach_the_admin(): void
    {
        $staff = User::factory()->create([
            'role' => 'manager',
            'email' => 'manager@example.com',
            'password' => Hash::make('Str0ng-Passw0rd!'),
            'is_active' => false,
        ]);

        $this->assertFalse($staff->isAdmin());

        $this->post('/login', [
            'login' => 'manager@example.com',
            'password' => 'Str0ng-Passw0rd!',
        ])->assertSessionHasErrors('login');
    }
}
