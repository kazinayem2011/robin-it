<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The email verification link works for somebody who is signed out.
 *
 * It needed its owner signed in first, so opened on another device, or after
 * the session had gone, it confirmed nothing. The signed link is proof enough
 * of whose inbox it came from, so it now confirms the address and signs its
 * owner in — and never swaps out a different account already signed in.
 *
 * Also here: how long a customer stays signed in afterwards.
 */
class EmailVerificationSignsInTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'phone' => '01712345678',
            'email' => 'karim@example.com',
            'email_verified_at' => null,
        ], $attributes));
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
    }

    /** Opened signed out — on another device, say — it signs its owner in. */
    public function test_the_verification_link_signs_in_a_signed_out_customer(): void
    {
        Event::fake([Verified::class]);
        $customer = $this->customer();

        $this->get($this->verificationUrl($customer))
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

        $this->assertAuthenticatedAs($customer);
        $this->assertTrue($customer->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    /** Somebody else signed in on this browser stays signed in as themselves. */
    public function test_the_verification_link_does_not_switch_a_different_signed_in_account(): void
    {
        $customer = $this->customer();
        $other = User::factory()->create(['email' => 'other@example.com']);

        $this->actingAs($other)
            ->get($this->verificationUrl($customer))
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1')
            ->assertSessionHas('info');

        $this->assertAuthenticatedAs($other);
        $this->assertTrue($customer->fresh()->hasVerifiedEmail());
    }

    public function test_a_verification_link_with_the_wrong_hash_does_nothing(): void
    {
        $customer = $this->customer();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $customer->id, 'hash' => sha1('someone-else@example.com'),
        ]);

        $this->get($url)->assertForbidden();

        $this->assertGuest();
        $this->assertFalse($customer->fresh()->hasVerifiedEmail());
    }

    public function test_an_unsigned_verification_link_does_nothing(): void
    {
        $customer = $this->customer();

        $this->get('/verify-email/'.$customer->id.'/'.sha1($customer->email))->assertForbidden();

        $this->assertGuest();
        $this->assertFalse($customer->fresh()->hasVerifiedEmail());
    }

    public function test_an_expired_verification_link_does_nothing(): void
    {
        $customer = $this->customer();
        $url = $this->verificationUrl($customer);

        $this->travel(61)->minutes();

        $this->get($url)->assertForbidden();

        $this->assertGuest();
        $this->assertFalse($customer->fresh()->hasVerifiedEmail());
    }

    /** Staff are confirmed, and sent to sign in with their password. */
    public function test_the_verification_link_does_not_sign_in_staff(): void
    {
        $admin = User::factory()->admin()->create(['email_verified_at' => null]);

        $this->get($this->verificationUrl($admin))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertTrue($admin->fresh()->hasVerifiedEmail());
    }

    public function test_the_verification_link_does_not_sign_in_a_suspended_account(): void
    {
        $customer = $this->customer(['is_active' => false]);

        $this->get($this->verificationUrl($customer))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /** Sixty days for a shopper, a week for staff, out of the box. */
    public function test_the_default_windows_are_sixty_days_and_a_week(): void
    {
        $restore = [
            'SESSION_LIFETIME' => $_ENV['SESSION_LIFETIME'] ?? null,
            'SESSION_ADMIN_LIFETIME' => $_ENV['SESSION_ADMIN_LIFETIME'] ?? null,
        ];

        foreach (array_keys($restore) as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            $config = require base_path('config/session.php');

            $this->assertSame(86400, $config['customer_lifetime']);
            $this->assertSame(86400, $config['lifetime']);
            $this->assertSame(10080, $config['admin_lifetime']);
            $this->assertFalse((bool) $config['expire_on_close'], 'A session that ends with the browser is not sixty days.');
        } finally {
            foreach ($restore as $key => $value) {
                if ($value !== null) {
                    $_ENV[$key] = $_SERVER[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }
    }
}
