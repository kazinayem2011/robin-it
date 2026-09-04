<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * One guard serves the storefront and the admin panel, so the two session
 * windows are decided per request rather than per guard.
 */
class SessionWindowTest extends TestCase
{
    use RefreshDatabase;

    /** Minutes, as config/session.php counts them. */
    private const CUSTOMER_WINDOW = 86400;   // 60 days

    private const ADMIN_WINDOW = 10080;      // 7 days

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned here rather than read from whatever .env the machine has.
        config([
            'session.lifetime' => self::CUSTOMER_WINDOW,
            'session.customer_lifetime' => self::CUSTOMER_WINDOW,
            'session.admin_lifetime' => self::ADMIN_WINDOW,
        ]);
    }

    public function test_a_guest_keeps_the_customer_window(): void
    {
        $response = $this->get('/');

        $this->assertCookieExpiresIn($response, config('session.cookie'), self::CUSTOMER_WINDOW);
    }

    public function test_a_signed_in_customer_gets_sixty_days(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer)->get('/');

        $this->assertCookieExpiresIn($response, config('session.cookie'), self::CUSTOMER_WINDOW);
    }

    public function test_an_admin_gets_one_week(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/');

        $this->assertCookieExpiresIn($response, config('session.cookie'), self::ADMIN_WINDOW);
    }

    public function test_an_admin_session_idle_past_its_week_is_ended(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->withSession(['admin_last_seen_at' => now()->subDays(8)->getTimestamp()])
            ->get('/dashboard');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_admin_seen_inside_the_week_stays_signed_in(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->withSession(['admin_last_seen_at' => now()->subDays(6)->getTimestamp()])
            ->get('/dashboard');

        $response->assertOk();
        $this->assertAuthenticated();
    }

    public function test_a_customer_idle_the_same_eight_days_is_untouched(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer)
            ->withSession(['admin_last_seen_at' => now()->subDays(8)->getTimestamp()])
            ->get('/dashboard');

        $response->assertOk();
        $this->assertAuthenticated();
    }

    public function test_an_expired_admin_api_call_is_answered_with_401(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->withSession(['admin_last_seen_at' => now()->subDays(8)->getTimestamp()])
            ->getJson('/api/admin/categories/search');

        $response->assertStatus(401);
    }

    public function test_a_customers_remember_cookie_lasts_the_customer_window(): void
    {
        User::factory()->create([
            'email' => 'shopper@robinit.com',
            'password' => Hash::make('password123'),
            'role' => User::ROLE_CUSTOMER,
        ]);

        $response = $this->post('/login', [
            'login' => 'shopper@robinit.com',
            'password' => 'password123',
            'remember' => true,
        ]);

        $this->assertCookieExpiresIn(
            $response,
            Auth::guard('web')->getRecallerName(),
            self::CUSTOMER_WINDOW
        );
    }

    /**
     * The one that would otherwise undo the week: Laravel queues the recaller
     * for 400 days, and it signs an admin back in the moment their session
     * cookie is gone.
     */
    public function test_an_admins_remember_cookie_is_cut_to_the_admin_window(): void
    {
        User::factory()->admin()->create([
            'email' => 'staff@robinit.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/login', [
            'login' => 'staff@robinit.com',
            'password' => 'password123',
            'remember' => true,
        ]);

        $this->assertCookieExpiresIn(
            $response,
            Auth::guard('web')->getRecallerName(),
            self::ADMIN_WINDOW
        );
    }

    public function test_login_without_remember_queues_no_recaller(): void
    {
        User::factory()->admin()->create([
            'email' => 'staff2@robinit.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/login', [
            'login' => 'staff2@robinit.com',
            'password' => 'password123',
        ]);

        $this->assertNull(
            $this->cookieNamed($response, Auth::guard('web')->getRecallerName()),
            'Signing in without "remember me" should set no recaller cookie.'
        );
    }

    /**
     * The cap in config/session.php, exercised through the file itself.
     *
     * Asserting that the pinned `session.admin_lifetime` is under the pinned
     * `session.customer_lifetime` proves nothing — setUp() writes both, so it
     * would pass just as well with the `min()` deleted. This loads the config
     * with a misconfigured pair instead.
     */
    public function test_the_admin_window_can_never_exceed_the_customer_one(): void
    {
        $restore = [
            'SESSION_LIFETIME' => $_ENV['SESSION_LIFETIME'] ?? null,
            'SESSION_ADMIN_LIFETIME' => $_ENV['SESSION_ADMIN_LIFETIME'] ?? null,
        ];

        // Staff asked for longer than the store keeps a session at all.
        $this->putEnv('SESSION_LIFETIME', '100');
        $this->putEnv('SESSION_ADMIN_LIFETIME', '999');

        try {
            $config = require base_path('config/session.php');

            $this->assertSame(100, $config['customer_lifetime']);
            $this->assertSame(
                100,
                $config['admin_lifetime'],
                'A staff window longer than the customer one must be capped: '
                .'the session handler expires every session by `lifetime`, so '
                .'the longer number could not be honoured anyway.'
            );
        } finally {
            foreach ($restore as $key => $value) {
                $this->putEnv($key, $value);
            }
        }
    }

    /** Set or clear an environment value everywhere `env()` looks for one. */
    private function putEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    private function assertCookieExpiresIn($response, string $name, int $minutes): void
    {
        $cookie = $this->cookieNamed($response, $name);

        $this->assertNotNull($cookie, "No [{$name}] cookie on the response.");

        // A few seconds of slack for the time the request itself took.
        $this->assertEqualsWithDelta(
            now()->addMinutes($minutes)->getTimestamp(),
            $cookie->getExpiresTime(),
            10,
            "The [{$name}] cookie should expire in {$minutes} minutes."
        );
    }

    private function cookieNamed($response, string $name): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }
}
