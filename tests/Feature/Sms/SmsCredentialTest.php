<?php

namespace Tests\Feature\Sms;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A gateway token is a credential, not a setting.
 *
 * Somebody holding one can spend the shop's SMS balance until it is gone and
 * send whatever they like under the shop's sender ID — the shop's name on a
 * stranger's phone. The SMTP password was already treated as a credential and
 * these two were not, which was an oversight rather than a decision.
 */
class SmsCredentialTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_a_saved_token_is_encrypted_in_the_table(): void
    {
        $this->actingAs($this->owner())
            ->postJson('/api/admin/settings', ['settings' => ['sms_token' => 'live-gateway-token']])
            ->assertOk();

        $stored = SiteSetting::where('key', 'sms_token')->value('value');

        $this->assertNotSame('live-gateway-token', $stored);
        $this->assertSame('live-gateway-token', Crypt::decryptString($stored));
    }

    public function test_the_token_never_reaches_the_browser(): void
    {
        SiteSetting::create(['key' => 'sms_token', 'value' => SmsService::encryptSecret('live-token')]);
        SiteSetting::create(['key' => 'sms_api_key', 'value' => SmsService::encryptSecret('live-key')]);
        SiteSetting::create(['key' => 'sms_sender_id', 'value' => 'ROBINS']);
        SiteSetting::flushCache();

        $props = $this->actingAs($this->owner())
            ->get('/admin/settings')
            ->assertOk()
            ->viewData('page')['props'];

        $keys = collect($props['settings'])->pluck('key');

        $this->assertNotContains('sms_token', $keys);
        $this->assertNotContains('sms_api_key', $keys);
        // The rest of the tab still has to be editable.
        $this->assertContains('sms_sender_id', $keys);

        // Not hiding in the encoded page either.
        $encoded = json_encode($props);
        $this->assertStringNotContainsString('live-token', $encoded);
        $this->assertStringNotContainsString('live-key', $encoded);

        // The form is told one exists so it can say so.
        $this->assertTrue($props['smsSecretsSet']['sms_token']);
        $this->assertTrue($props['smsSecretsSet']['sms_api_key']);
    }

    /**
     * The field always arrives empty, because the value is never sent out. If
     * empty meant "clear it", opening the SMS tab and saving anything at all
     * would silently disconnect the gateway.
     */
    public function test_saving_with_the_field_blank_keeps_the_token(): void
    {
        SiteSetting::create(['key' => 'sms_token', 'value' => SmsService::encryptSecret('live-token')]);
        SiteSetting::flushCache();

        $this->actingAs($this->owner())
            ->postJson('/api/admin/settings', ['settings' => [
                'sms_token' => '',
                'sms_sender_id' => 'ROBINS',
            ]])
            ->assertOk();

        SiteSetting::flushCache();

        $this->assertSame(
            'live-token',
            Crypt::decryptString(SiteSetting::where('key', 'sms_token')->value('value'))
        );
        $this->assertSame('ROBINS', SiteSetting::get('sms_sender_id'));
    }

    public function test_typing_a_new_token_replaces_the_old_one(): void
    {
        SiteSetting::create(['key' => 'sms_token', 'value' => SmsService::encryptSecret('old-token')]);
        SiteSetting::flushCache();

        $this->actingAs($this->owner())
            ->postJson('/api/admin/settings', ['settings' => ['sms_token' => 'new-token']])
            ->assertOk();

        SiteSetting::flushCache();

        $this->assertSame(
            'new-token',
            Crypt::decryptString(SiteSetting::where('key', 'sms_token')->value('value'))
        );
    }

    /**
     * Encrypting is only worth anything if sending still works, which means
     * the read path has to decrypt.
     *
     * Driven through send() rather than by reaching into the class: what
     * matters is that the gateway receives the real token, not that some
     * private method returns the right string.
     */
    public function test_the_gateway_is_given_the_decrypted_token(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('SMS SUBMITTED SUCCESSFULLY')]);

        SiteSetting::create(['key' => 'sms_enabled', 'value' => '1']);
        SiteSetting::create(['key' => 'sms_token', 'value' => SmsService::encryptSecret('live-token')]);
        SiteSetting::flushCache();

        $this->assertTrue(app(SmsService::class)->send('01712345678', 'Test message.'));

        Http::assertSent(fn ($request) => $request->data()['token'] === 'live-token');
    }

    /** A token written by hand before this existed must still work. */
    public function test_a_plain_token_saved_before_encryption_still_reads(): void
    {
        SiteSetting::create(['key' => 'sms_token', 'value' => 'written-by-hand']);
        SiteSetting::flushCache();

        $this->assertTrue(SmsService::isSecretSet('sms_token'));
    }

    public function test_the_storefront_never_sees_them(): void
    {
        SiteSetting::create(['key' => 'sms_token', 'value' => SmsService::encryptSecret('live-token')]);
        SiteSetting::flushCache();

        $this->assertNotContains('sms_token', SiteSetting::publicKeys());
        $this->assertNotContains('sms_api_key', SiteSetting::publicKeys());
    }
}
