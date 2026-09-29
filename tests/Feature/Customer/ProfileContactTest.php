<?php

namespace Tests\Feature\Customer;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer's own details: one way to reach them, as at sign-up, and a new
 * number or address is not "verified" until it is.
 *
 * Both an email and a mobile were required here, so an account made with a
 * mobile alone could not change its name without inventing an email. And a
 * changed number kept the old one's "verified".
 */
class ProfileContactTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes): User
    {
        return User::factory()->create(['role' => 'customer'] + $attributes);
    }

    public function test_a_mobile_only_account_can_change_its_name(): void
    {
        $user = $this->customer(['email' => null, 'phone' => '01712345678', 'phone_verified_at' => now()]);

        $this->actingAs($user)->post('/account/profile', [
            'name' => 'Rahim Renamed', 'email' => '', 'phone' => '01712345678',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Rahim Renamed', $user->fresh()->name);
        $this->assertNull($user->fresh()->email);
        $this->assertNotNull($user->fresh()->phone_verified_at, 'same number, still verified');
    }

    public function test_an_email_only_account_need_not_add_a_mobile(): void
    {
        $user = $this->customer(['email' => 'rahim@example.com', 'phone' => null]);

        $this->actingAs($user)->post('/account/profile', [
            'name' => 'Rahim', 'email' => 'rahim@example.com', 'phone' => '',
        ])->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->phone);
    }

    public function test_one_of_the_two_is_still_needed(): void
    {
        $user = $this->customer(['email' => 'rahim@example.com', 'phone' => '01712345678']);

        $this->actingAs($user)->post('/account/profile', [
            'name' => 'Rahim', 'email' => '', 'phone' => '',
        ])->assertSessionHasErrors(['email', 'phone']);
    }

    public function test_a_new_number_is_not_verified_until_it_is(): void
    {
        $user = $this->customer(['phone' => '01712345678', 'phone_verified_at' => now()]);

        $this->actingAs($user)->post('/account/profile', [
            'name' => $user->name, 'email' => $user->email, 'phone' => '01812345678',
        ])->assertSessionHasNoErrors();

        $this->assertSame('01812345678', $user->fresh()->phone);
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_a_new_email_is_not_verified_until_it_is(): void
    {
        $user = $this->customer(['email' => 'old@example.com', 'email_verified_at' => now(), 'phone' => '01712345678']);

        $this->actingAs($user)->post('/account/profile', [
            'name' => $user->name, 'email' => 'New@Example.com', 'phone' => '01712345678',
        ])->assertSessionHasNoErrors();

        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
    }
}
