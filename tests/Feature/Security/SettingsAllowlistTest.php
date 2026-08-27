<?php

namespace Tests\Feature\Security;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Settings save validated the *shape* of each value and never looked at the
 * key, so any key at all could be written into the settings table. What reaches
 * the browser was then decided by a denylist of name patterns — so a key that
 * dodged those patterns landed in the props of every public page.
 */
class SettingsAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function save(array $settings)
    {
        return $this->actingAs($this->admin())
            ->postJson('/api/admin/settings', ['settings' => $settings]);
    }

    public function test_a_known_setting_is_saved(): void
    {
        $this->save(['site_name' => 'Robins Computer'])->assertStatus(200);

        SiteSetting::flushCache();
        $this->assertSame('Robins Computer', SiteSetting::get('site_name'));
    }

    public function test_an_unknown_key_is_refused(): void
    {
        $this->save(['payment_gateway_creds' => 'sk_live_abc'])
            ->assertStatus(422)
            ->assertJsonPath('error', true);

        $this->assertDatabaseMissing('site_settings', ['key' => 'payment_gateway_creds']);
    }

    /** One bad key must not let the rest of the form through either. */
    public function test_a_batch_containing_an_unknown_key_writes_nothing(): void
    {
        $this->save([
            'site_name' => 'Should Not Persist',
            'totally_made_up' => 'x',
        ])->assertStatus(422);

        SiteSetting::flushCache();
        $this->assertDatabaseMissing('site_settings', ['key' => 'site_name']);
        $this->assertDatabaseMissing('site_settings', ['key' => 'totally_made_up']);
    }

    public function test_every_editable_key_is_accepted(): void
    {
        $payload = [];

        foreach (SiteSetting::editableKeys() as $key) {
            // mail_password treats '' as "leave it alone", so give it a value.
            $payload[$key] = 'x';
        }

        $this->save($payload)->assertStatus(200);
    }

    /** Credentials are writable but must never be published. */
    public function test_mail_keys_are_editable_but_never_public(): void
    {
        foreach (['mail_host', 'mail_username', 'mail_password'] as $key) {
            $this->assertContains($key, SiteSetting::editableKeys(), "{$key} should be editable");
            $this->assertNotContains($key, SiteSetting::publicKeys(), "{$key} must not be public");
        }
    }

    /** Nothing the model calls private may appear in the published set. */
    public function test_no_public_key_is_a_private_one(): void
    {
        foreach (SiteSetting::publicKeys() as $key) {
            $this->assertFalse(
                SiteSetting::isPrivateKey($key),
                "{$key} is published but the model calls it private"
            );
        }
    }
}
