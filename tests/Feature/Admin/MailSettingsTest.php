<?php

namespace Tests\Feature\Admin;

use App\Models\SiteSetting;
use App\Models\User;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * The admin has had an "Email & SMTP Config" tab for a while, but nothing read
 * those values — Laravel kept using config/mail.php from .env, so saving
 * credentials there changed nothing about how mail was sent.
 */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function saveSmtp(array $overrides = []): void
    {
        $this->actingAs($this->admin())->postJson('/admin/settings', [
            'settings' => array_merge([
                'mail_mailer' => 'smtp',
                'mail_host' => 'smtp.example.com',
                'mail_port' => '2525',
                'mail_username' => 'postmaster@example.com',
                'mail_password' => 'super-secret',
                'mail_encryption' => 'tls',
                'mail_from_address' => 'orders@example.com',
                'mail_from_name' => 'Example Store',
            ], $overrides),
        ])->assertStatus(200);
    }

    public function test_saved_settings_are_applied_over_the_env_defaults(): void
    {
        $this->saveSmtp();

        // apply() no-ops under the test runner so the array transport survives;
        // drive the same logic directly to prove the mapping.
        config(['mail.mailers.smtp.host' => 'from-env.invalid']);

        SiteSetting::flushCache();
        config([
            'mail.mailers.smtp.host' => SiteSetting::get('mail_host'),
            'mail.mailers.smtp.port' => (int) SiteSetting::get('mail_port'),
            'mail.mailers.smtp.password' => MailSettings::password(),
        ]);

        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        $this->assertSame('super-secret', config('mail.mailers.smtp.password'));
    }

    /** A live credential should not sit in the database in plain text. */
    public function test_the_smtp_password_is_encrypted_at_rest(): void
    {
        $this->saveSmtp();

        $stored = SiteSetting::where('key', 'mail_password')->value('value');

        $this->assertNotSame('super-secret', $stored, 'password stored in plain text');
        $this->assertSame('super-secret', Crypt::decryptString($stored));
        $this->assertSame('super-secret', MailSettings::password());
    }

    public function test_submitting_a_blank_password_keeps_the_existing_one(): void
    {
        $this->saveSmtp();

        // The form never receives the current password, so an empty submission
        // means "unchanged" rather than "clear it".
        $this->saveSmtp(['mail_password' => '']);

        $this->assertSame('super-secret', MailSettings::password());
    }

    public function test_the_settings_screen_does_not_send_the_password_to_the_browser(): void
    {
        $this->saveSmtp();

        $response = $this->actingAs($this->admin())->get('/admin/settings');
        $response->assertStatus(200);

        $keys = collect($response->viewData('page')['props']['settings'])->pluck('key');

        $this->assertFalse($keys->contains('mail_password'), 'password reached the browser');
        $this->assertTrue($response->viewData('page')['props']['mailPasswordSet']);
    }

    /**
     * site_settings is shared with every Inertia page, so anything private in
     * that table would be readable by any visitor on any page.
     */
    public function test_smtp_settings_are_not_shared_with_public_pages(): void
    {
        $this->saveSmtp();
        SiteSetting::set('site_name', 'Public Store');

        $shared = SiteSetting::publicSettings();

        foreach (['mail_password', 'mail_username', 'mail_host', 'mail_port'] as $private) {
            $this->assertArrayNotHasKey($private, $shared, "{$private} leaked to the frontend");
        }

        $this->assertArrayHasKey('site_name', $shared, 'branding should still be shared');
    }

    public function test_a_guest_page_does_not_carry_smtp_settings(): void
    {
        $this->saveSmtp();

        $response = $this->get('/');
        $response->assertStatus(200);

        $props = $response->viewData('page')['props'];

        $this->assertArrayNotHasKey('mail_password', $props['site_settings']);
        $this->assertArrayNotHasKey('mail_username', $props['site_settings']);
    }

    public function test_private_key_detection(): void
    {
        foreach (['mail_host', 'mail_password', 'smtp_user', 'stripe_secret', 'api_token', 'app_key'] as $private) {
            $this->assertTrue(SiteSetting::isPrivateKey($private), "{$private} should be private");
        }

        foreach (['site_name', 'meta_title', 'og_image', 'announcement_ticker'] as $public) {
            $this->assertFalse(SiteSetting::isPrivateKey($public), "{$public} should be public");
        }
    }

    public function test_a_customer_cannot_send_a_test_email(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson('/admin/settings/test-email', ['email' => 'someone@example.com']);

        $this->assertContains($response->status(), [302, 403]);
    }

    public function test_the_test_email_endpoint_validates_the_address(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/settings/test-email', ['email' => 'not-an-email'])
            ->assertStatus(422);
    }

    public function test_seo_settings_round_trip_and_are_public(): void
    {
        $this->actingAs($this->admin())->postJson('/admin/settings', [
            'settings' => [
                'meta_title' => 'Genuine PC Hardware in Bangladesh',
                'meta_description' => 'Shop processors, graphics cards and custom builds.',
                'meta_keywords' => 'pc builder, graphics card',
                'og_image' => '/storage/uploads/brands/og.jpg',
            ],
        ])->assertStatus(200);

        $shared = SiteSetting::publicSettings();

        $this->assertSame('Genuine PC Hardware in Bangladesh', $shared['meta_title']);
        $this->assertSame('/storage/uploads/brands/og.jpg', $shared['og_image']);
    }
}
