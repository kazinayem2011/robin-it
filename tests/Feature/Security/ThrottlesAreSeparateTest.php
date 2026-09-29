<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Each rate limit counts only its own route.
 *
 * `throttle:6,1` keys by visitor alone, so every route written that way shared
 * one counter per IP: asking for a sign-in link, mistyping a code and opening
 * the verification email all spent the same six tries, and a customer who got
 * a code wrong twice could find the email link refused for ten minutes. The
 * third argument names the counter; every numeric limit now has one.
 */
class ThrottlesAreSeparateTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_numeric_limit_names_its_own_counter(): void
    {
        $unnamed = [];
        $names = [];

        foreach (['routes/auth.php', 'routes/web.php', 'routes/api.php'] as $file) {
            preg_match_all("/'throttle:(\d+),(\d+)(?:,([\w-]+))?'/", File::get(base_path($file)), $matches, PREG_SET_ORDER);

            foreach ($matches as $m) {
                if (empty($m[3])) {
                    $unnamed[] = "{$file}: {$m[0]}";
                } else {
                    $names[] = $m[3];
                }
            }
        }

        $this->assertSame([], $unnamed, 'A numeric throttle without a name shares its counter with every other.');
        $this->assertSame(count($names), count(array_unique($names)), 'Two routes share a counter name.');
    }

    public function test_using_up_one_limit_leaves_another_open(): void
    {
        // Eight asks for a password-reset code: that route's limit. Every one
        // is counted, whatever it is answered with.
        for ($i = 0; $i < 8; $i++) {
            $this->assertNotSame(429, $this->postJson('/otp/password', ['phone' => '0171234567'.$i])->status());
        }
        $this->postJson('/otp/password', ['phone' => '01712345678'])->assertStatus(429);

        // A different limited route is untouched by that.
        $this->assertNotSame(429, $this->postJson('/forgot-password', ['email' => 'nobody@example.com'])->status());
    }
}
