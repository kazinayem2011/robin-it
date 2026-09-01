<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * An account needs one way to reach its owner, not two.
 *
 * Signing in has always taken either: LoginRequest reads the field, decides
 * whether it looks like an address or a Bangladeshi mobile, and authenticates
 * on whichever it is. Signing up demanded both and the column was NOT NULL, so
 * the identifier most customers here actually have was never enough by itself.
 */
class RegisterWithEitherIdentifierTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Robin Rahman',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ], $overrides);
    }

    public function test_a_mobile_number_is_enough(): void
    {
        $this->post('/register', $this->form(['phone' => '01711223344']))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['phone' => '01711223344', 'email' => null]);
    }

    public function test_an_email_address_is_enough(): void
    {
        $this->post('/register', $this->form(['email' => 'robin@example.com']))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'robin@example.com', 'phone' => null]);
    }

    public function test_both_together_still_work(): void
    {
        $this->post('/register', $this->form([
            'email' => 'robin@example.com',
            'phone' => '01711223344',
        ]))->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('users', [
            'email' => 'robin@example.com',
            'phone' => '01711223344',
        ]);
    }

    public function test_neither_is_refused_and_says_so(): void
    {
        $this->post('/register', $this->form())
            ->assertSessionHasErrors(['email', 'phone']);

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    /**
     * An empty box must be stored as absent. '' would occupy the unique index,
     * and the second customer signing up with a mobile alone would be told the
     * address was already taken.
     */
    public function test_two_accounts_can_each_have_no_address(): void
    {
        $this->post('/register', $this->form(['phone' => '01711223344']));
        $this->post('/logout');
        $this->post('/register', $this->form(['phone' => '01822334455', 'name' => 'Karim']));

        $this->assertSame(2, User::whereNull('email')->count());
    }

    public function test_two_accounts_can_each_have_no_mobile(): void
    {
        $this->post('/register', $this->form(['email' => 'a@example.com']));
        $this->post('/logout');
        $this->post('/register', $this->form(['email' => 'b@example.com', 'name' => 'Karim']));

        $this->assertSame(2, User::whereNull('phone')->count());
    }

    public function test_an_address_already_in_use_is_still_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', $this->form(['email' => 'taken@example.com']))
            ->assertSessionHasErrors('email');
    }

    public function test_a_mobile_already_in_use_is_still_refused(): void
    {
        User::factory()->create(['phone' => '01711223344']);

        $this->post('/register', $this->form(['phone' => '01711223344']))
            ->assertSessionHasErrors('phone');
    }

    /** Nothing to send to, so nothing is sent. */
    public function test_no_verification_mail_is_attempted_without_an_address(): void
    {
        Notification::fake();

        $this->post('/register', $this->form(['phone' => '01711223344']));

        Notification::assertNothingSent();
    }

    public function test_a_verification_mail_still_goes_out_when_there_is_an_address(): void
    {
        Event::fake([Registered::class]);

        $this->post('/register', $this->form(['email' => 'robin@example.com']));

        Event::assertDispatched(Registered::class);
    }

    /**
     * Without this an account that can never receive a link is unverified for
     * ever, and any route behind the `verified` middleware would be shut to it.
     */
    public function test_an_account_with_no_address_is_not_left_permanently_unverified(): void
    {
        $this->post('/register', $this->form(['phone' => '01711223344']));

        $this->assertTrue(User::firstOrFail()->hasVerifiedEmail());
    }

    /** Signing in by either identifier still works afterwards. */
    public function test_the_new_account_can_sign_in_with_its_mobile(): void
    {
        $this->post('/register', $this->form(['phone' => '01711223344']));
        $this->post('/logout');

        $this->post('/login', ['login' => '01711223344', 'password' => 'correct-horse-battery'])
            ->assertRedirect();

        $this->assertAuthenticated();
    }
}
