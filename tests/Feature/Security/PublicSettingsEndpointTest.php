<?php

namespace Tests\Feature\Security;

use App\Models\SiteSetting;
use App\Models\User;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/settings is public and unauthenticated, and it returned
 * getAllSettings() — the entire table. The SMTP host, port, username and the
 * encrypted password were readable by anyone who asked for it.
 *
 * The Inertia share was fixed to filter these; this second door was left open.
 */
class PublicSettingsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function saveSmtp(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/admin/settings', [
                'settings' => [
                    'mail_host' => 'smtp.example.com',
                    'mail_port' => '2525',
                    'mail_username' => 'postmaster@example.com',
                    'mail_password' => 'super-secret',
                    'mail_encryption' => 'tls',
                    'site_name' => 'Robins Computer',
                    'announcement_text' => '⚡ Flash sale: everything must go.',
                ],
            ])->assertStatus(200);

        SiteSetting::flushCache();
    }

    public function test_a_guest_cannot_read_the_smtp_credentials(): void
    {
        $this->saveSmtp();

        $settings = $this->getJson('/api/settings')->assertStatus(200)->json('data');

        foreach (['mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption'] as $private) {
            $this->assertArrayNotHasKey($private, $settings, "{$private} is readable at /api/settings");
        }
    }

    /** Not merely absent by name — the values must not appear at all. */
    public function test_no_credential_value_appears_anywhere_in_the_payload(): void
    {
        $this->saveSmtp();

        $body = $this->getJson('/api/settings')->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('smtp.example.com', $body);
        $this->assertStringNotContainsString('postmaster@example.com', $body);
        $this->assertStringNotContainsString('super-secret', $body);
        $this->assertStringNotContainsString(MailSettings::password() ?? 'super-secret', $body);
    }

    /** Every rule in one place, so a new private prefix is covered here too. */
    public function test_the_payload_holds_nothing_the_model_calls_private(): void
    {
        $this->saveSmtp();
        SiteSetting::set('stripe_secret', 'sk_live_abc');
        SiteSetting::set('smtp_relay', 'relay.example.com');
        SiteSetting::flushCache();

        $settings = $this->getJson('/api/settings')->assertStatus(200)->json('data');

        foreach (array_keys($settings) as $key) {
            $this->assertFalse(
                SiteSetting::isPrivateKey($key),
                "{$key} is private but reached a public endpoint"
            );
        }
    }

    /** The header, footer and ticker read this endpoint, so it must still work. */
    public function test_the_public_branding_and_ticker_still_come_through(): void
    {
        $this->saveSmtp();

        $settings = $this->getJson('/api/settings')->assertStatus(200)->json('data');

        $this->assertSame('Robins Computer', $settings['site_name']);
        $this->assertSame('⚡ Flash sale: everything must go.', $settings['announcement_text']);
    }
}
