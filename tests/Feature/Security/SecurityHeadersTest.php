<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The three headers every response carries.
 *
 * Not a Content-Security-Policy and not HSTS — both need to be written against
 * this application specifically, and a guessed one either breaks the site or
 * is decoration. These three are safe everywhere.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public static function pages(): array
    {
        return [
            'the storefront' => ['/'],
            'a sign-in page' => ['/login'],
            'an API endpoint' => ['/api/products'],
            'a page that does not exist' => ['/nothing-is-here'],
        ];
    }

    #[DataProvider('pages')]
    public function test_every_response_carries_them(string $uri): void
    {
        $response = $this->get($uri);

        foreach (self::EXPECTED as $header => $value) {
            $this->assertSame($value, $response->headers->get($header), "{$uri} is missing {$header}.");
        }
    }

    public function test_an_admin_page_carries_them_too(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/products');

        foreach (self::EXPECTED as $header => $value) {
            $this->assertSame($value, $response->headers->get($header));
        }
    }

    /**
     * The uploads are served by the web server straight off the disk, so no
     * middleware ever sees them — public/.htaccess has to say the same thing.
     * This asserts the two lists have not drifted apart.
     */
    public function test_the_web_server_is_told_the_same_thing(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));

        foreach (self::EXPECTED as $header => $value) {
            $this->assertStringContainsString(
                "Header always set {$header} \"{$value}\"",
                $htaccess,
                "public/.htaccess does not set {$header}, so files under /storage go without it."
            );
        }
    }
}
