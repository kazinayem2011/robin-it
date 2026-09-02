<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The headers every response carries.
 *
 * Still not a Content-Security-Policy: that one has to be written against this
 * application's own pages and inline scripts, and a guessed policy either
 * breaks the site or is decoration. The four below are safe everywhere.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Strict-Transport-Security' => 'max-age=31536000',
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

    /**
     * PHP names its exact patch version on every response. No middleware can
     * take it off — PHP writes it at the SAPI level, below anything Laravel
     * holds — so the web server has to strip it on the way out.
     */
    public function test_the_php_version_is_not_advertised(): void
    {
        $this->assertStringContainsString(
            'Header always unset X-Powered-By',
            file_get_contents(public_path('.htaccess')),
            'public/.htaccess does not strip X-Powered-By, so every response names the PHP patch version.'
        );
    }

    /**
     * HSTS is the one header here that a browser keeps after we stop sending
     * it, so the max-age is worth pinning down rather than leaving to a later
     * copy-paste: a stray extra zero is eleven years of being unable to serve
     * this host over plaintext, and there is no taking it back.
     */
    public function test_the_hsts_lifetime_is_a_year_and_claims_no_subdomains(): void
    {
        // The directive's own value, not the whole file — the comment above it
        // explains why includeSubDomains and preload are absent, and so names
        // them both.
        preg_match(
            '/^\s*Header always set Strict-Transport-Security "([^"]*)"/m',
            file_get_contents(public_path('.htaccess')),
            $directive
        );

        $this->assertNotEmpty($directive, 'public/.htaccess does not set Strict-Transport-Security.');

        $this->assertSame(
            self::EXPECTED['Strict-Transport-Security'],
            $directive[1],
            'The web server and the middleware disagree about the HSTS lifetime.'
        );

        $this->assertSame(
            'max-age=31536000',
            $directive[1],
            'The HSTS lifetime changed. A browser holds the old one until it expires regardless.'
        );

        $this->assertStringNotContainsString(
            'includeSubDomains',
            $directive[1],
            'webmail and cPanel answer on subdomains of this name; their TLS is not ours to vouch for.'
        );

        $this->assertStringNotContainsString(
            'preload',
            $directive[1],
            'Preloading is baked into browser binaries and is genuinely hard to undo.'
        );
    }
}
