<?php

namespace Tests\Feature\Sms;

use App\Models\SiteSetting;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * A credential that has been cleared is actually gone.
 *
 * Settings are read through a cache that holds them for an hour, and only
 * SiteSetting::set() dropped it. So a token cleared on the row itself — the
 * obvious way, and the way anyone reaches for at a console when they want a
 * borrowed credential out of a shop — left the service still holding it: the
 * row read empty while isSecretSet() answered true and the gateway would
 * still have been given the token to send with.
 *
 * A stale shop name is a nuisance for an hour. A credential that outlives its
 * own deletion is the thing worth a test.
 */
class ClearedCredentialTest extends TestCase
{
    use RefreshDatabase;

    private function storeToken(string $token): SiteSetting
    {
        return SiteSetting::create([
            'key' => 'sms_token',
            'value' => Crypt::encryptString($token),
            'group' => 'sms',
        ]);
    }

    public function test_emptying_the_row_takes_the_credential_out_of_the_service(): void
    {
        $setting = $this->storeToken('borrowed-token');

        // Read it first, so the cache is holding the token, as it would be on
        // any shop that has sent an SMS in the last hour.
        $this->assertTrue(SmsService::isSecretSet('sms_token'));

        $setting->value = '';
        $setting->save();

        $this->assertSame('', SiteSetting::get('sms_token'));
        $this->assertFalse(
            SmsService::isSecretSet('sms_token'),
            'The service still had the token after the row was emptied.'
        );
    }

    public function test_deleting_the_row_does_the_same(): void
    {
        $setting = $this->storeToken('borrowed-token');
        $this->assertTrue(SmsService::isSecretSet('sms_token'));

        $setting->delete();

        $this->assertNull(SiteSetting::get('sms_token'));
        $this->assertFalse(SmsService::isSecretSet('sms_token'));
    }

    public function test_a_changed_value_is_read_back_not_the_one_before_it(): void
    {
        $setting = SiteSetting::create([
            'key' => 'site_name',
            'value' => 'Robins Computer',
            'group' => 'general',
        ]);

        $this->assertSame('Robins Computer', SiteSetting::get('site_name'));

        $setting->update(['value' => 'Robin IT']);

        $this->assertSame('Robin IT', SiteSetting::get('site_name'));
    }

    public function test_the_whole_settings_map_is_dropped_too(): void
    {
        $setting = SiteSetting::create([
            'key' => 'site_name',
            'value' => 'Robins Computer',
            'group' => 'general',
        ]);

        $this->assertSame('Robins Computer', SiteSetting::getAllSettings()['site_name']);

        $setting->update(['value' => 'Robin IT']);

        $this->assertSame('Robin IT', SiteSetting::getAllSettings()['site_name']);
    }
}
