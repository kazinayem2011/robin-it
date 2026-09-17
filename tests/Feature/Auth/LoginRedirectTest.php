<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Signing in goes back to the page that asked for it.
 *
 * The storefront sent people to /login?redirect=… when a session ran out, and
 * checkout now sends a customer whose email already has an account the same
 * way — but nothing read the parameter, so everyone landed on the dashboard
 * and had to find their way back.
 */
class LoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): TestResponse
    {
        $user = User::factory()->create([
            'email' => 'karim@example.com',
            'password' => Hash::make('password123'),
        ]);

        return $this->post('/login', ['login' => $user->email, 'password' => 'password123']);
    }

    public function test_signing_in_returns_to_the_page_that_sent_you(): void
    {
        $this->get('/login?redirect='.urlencode('/checkout'))->assertOk();

        $this->signIn()->assertRedirect('/checkout');
    }

    /** Anything that could leave the site is ignored, not followed. */
    #[DataProvider('elsewhere')]
    public function test_a_redirect_off_the_site_is_ignored(string $redirect): void
    {
        $this->get('/login?redirect='.urlencode($redirect))->assertOk();

        $this->signIn()->assertRedirect(route('dashboard', absolute: false));
    }

    public static function elsewhere(): array
    {
        return [
            'another site' => ['https://evil.example'],
            'protocol-relative' => ['//evil.example'],
            'backslash' => ['/\\evil.example'],
            'not a path' => ['checkout'],
            'header injection' => ["/checkout\r\nSet-Cookie: x=1"],
        ];
    }
}
