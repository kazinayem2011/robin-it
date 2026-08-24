<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Brute-force and abuse limits on the unauthenticated auth endpoints.
 *
 * These are the endpoints anyone on the internet can reach without a session,
 * so they are where guessing and enumeration happen.
 */
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
    }

    public function test_repeated_wrong_passwords_are_locked_out(): void
    {
        $user = User::factory()->create(['email' => 'target@example.com']);

        $lastStatus = null;

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $lastStatus = $this->post('/login', [
                'login' => 'target@example.com',
                'password' => 'wrong-guess-'.$attempt,
            ])->status();
        }

        // Laravel's LoginRequest throttles per email+IP and throws a
        // validation error once the limit is hit rather than 429.
        $response = $this->post('/login', [
            'login' => 'target@example.com',
            'password' => 'wrong-again',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertStringContainsString(
            'seconds',
            collect($response->baseResponse->getSession()->get('errors')->get('login'))->first(),
            'guessing was never rate limited'
        );
    }

    public function test_account_creation_is_rate_limited(): void
    {
        $statuses = [];

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $statuses[] = $this->post('/register', [
                'name' => "Spam {$attempt}",
                'email' => "spam{$attempt}@example.com",
                'phone' => '0171234'.str_pad((string) $attempt, 4, '0', STR_PAD_LEFT),
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])->status();
        }

        $this->assertContains(429, $statuses, 'an unlimited number of accounts could be created');
    }

    /**
     * Unlimited reset requests let someone both spam a mailbox and enumerate
     * which addresses have accounts.
     */
    public function test_password_reset_requests_are_rate_limited(): void
    {
        User::factory()->create(['email' => 'target@example.com']);

        $statuses = [];

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $statuses[] = $this->post('/forgot-password', [
                'email' => "probe{$attempt}@example.com",
            ])->status();
        }

        $this->assertContains(429, $statuses, 'addresses could be enumerated without limit');
    }

    public function test_reset_token_guessing_is_rate_limited(): void
    {
        $user = User::factory()->create(['email' => 'target@example.com']);

        $statuses = [];

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $statuses[] = $this->post('/reset-password', [
                'token' => 'guessed-token-'.$attempt,
                'email' => 'target@example.com',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])->status();
        }

        $this->assertContains(429, $statuses, 'reset tokens could be guessed without limit');
    }
}
