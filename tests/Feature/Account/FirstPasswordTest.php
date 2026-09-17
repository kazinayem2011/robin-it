<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An account made at checkout has no password until its owner sets one.
 *
 * It used to be given a random one nobody knew, which made it look exactly like
 * an account with a real password — so checkout asked its owner for a password
 * they had never chosen, and the profile asked for a "current" one to change.
 */
class FirstPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function madeAtCheckout(): User
    {
        return User::factory()->create([
            'phone' => '01712345678',
            'email' => null,
            'password' => null,
        ]);
    }

    public function test_the_first_password_needs_no_current_one(): void
    {
        $customer = $this->madeAtCheckout();

        $this->actingAs($customer)
            ->put('/account/password', [
                'password' => 'new-secret-1',
                'password_confirmation' => 'new-secret-1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($customer->fresh()->hasPassword());

        // And it signs in with it.
        auth()->logout();
        $this->post('/login', ['login' => '01712345678', 'password' => 'new-secret-1']);
        $this->assertAuthenticatedAs($customer);
    }

    public function test_changing_an_existing_password_still_needs_the_current_one(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->put('/account/password', [
                'password' => 'new-secret-1',
                'password_confirmation' => 'new-secret-1',
            ])
            ->assertSessionHasErrors('current_password');
    }

    /** No password is not an empty password: nothing signs in to it. */
    public function test_an_account_with_no_password_cannot_be_signed_into_with_one(): void
    {
        $this->madeAtCheckout();

        foreach (['', 'password', 'anything'] as $guess) {
            $this->post('/login', ['login' => '01712345678', 'password' => $guess]);
            $this->assertGuest();
        }
    }

    public function test_the_profile_says_which_form_to_draw(): void
    {
        $this->actingAs($this->madeAtCheckout())
            ->get('/dashboard/profile')
            ->assertInertia(fn ($page) => $page->where('hasPassword', false));

        $this->actingAs(User::factory()->create())
            ->get('/dashboard/profile')
            ->assertInertia(fn ($page) => $page->where('hasPassword', true));
    }
}
