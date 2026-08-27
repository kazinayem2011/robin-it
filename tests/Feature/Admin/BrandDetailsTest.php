<?php

namespace Tests\Feature\Admin;

use App\Models\SiteSetting;
use App\Models\User;
use App\Support\BrandDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Branding was written into the frontend as literals, so an admin could edit
 * the shop's name, legal name, hotline or addresses and the site carried on
 * showing something else. These cover the resolution the storefront depends on.
 */
class BrandDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legal_name_comes_from_settings(): void
    {
        SiteSetting::set('site_legal_name', 'Acme Tech Trading PLC');

        $this->assertSame('Acme Tech Trading PLC', BrandDetails::legalName());
    }

    /**
     * The footer prints "{legal name}. All Rights Reserved", so a name saved
     * with its own full stop came out as "Ltd.. All Rights Reserved".
     */
    public function test_a_trailing_full_stop_is_trimmed(): void
    {
        SiteSetting::set('site_legal_name', 'Robins Computer & Technology Ltd.');

        $this->assertSame('Robins Computer & Technology Ltd', BrandDetails::legalName());
    }

    public function test_the_legal_name_falls_back_to_the_shop_name(): void
    {
        SiteSetting::set('site_name', 'Robins Computer');

        $this->assertSame('Robins Computer', BrandDetails::legalName());
    }

    public function test_a_blank_legal_name_does_not_win(): void
    {
        SiteSetting::set('site_name', 'Robins Computer');
        SiteSetting::set('site_legal_name', '   ');

        $this->assertSame('Robins Computer', BrandDetails::legalName());
    }

    public function test_the_hotline_prefers_the_key_the_storefront_reads(): void
    {
        SiteSetting::set('site_hotline', '09600-ROBIN-IT');
        SiteSetting::set('hotline_number', '16789');

        $this->assertSame('16789', BrandDetails::hotline());
    }

    /** An install that only ever had the older key keeps its number. */
    public function test_the_hotline_falls_back_to_the_legacy_key(): void
    {
        SiteSetting::set('site_hotline', '09600-ROBIN-IT');

        $this->assertSame('09600-ROBIN-IT', BrandDetails::hotline());
    }

    public function test_the_tel_link_keeps_only_dialable_characters(): void
    {
        SiteSetting::set('hotline_number', '+880 9600-123 456');

        $this->assertSame('tel:+8809600123456', BrandDetails::hotlineHref());
    }

    /**
     * The frontend reads these off the shared props, so they have to be public
     * — and they must be there at all.
     */
    public function test_the_branding_keys_reach_the_browser(): void
    {
        SiteSetting::set('site_legal_name', 'Acme Tech Trading PLC');
        SiteSetting::set('sales_email', 'hello@acme.test');
        SiteSetting::set('service_center_address', 'Multiplan Center, Dhaka');
        SiteSetting::set('hotline_number', '16789');

        $shared = SiteSetting::publicSettings();

        foreach (['site_legal_name', 'sales_email', 'service_center_address', 'hotline_number'] as $key) {
            $this->assertArrayHasKey($key, $shared, "{$key} never reaches the frontend");
        }
    }

    /**
     * The whole path the admin form takes: post the settings, then read what
     * the storefront would render. Each piece was covered; the round trip was
     * not, and an intermediate save writing a blank would show up here as the
     * legal name quietly collapsing into the shop name.
     */
    public function test_the_settings_form_round_trips_the_branding(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/settings', [
            'settings' => [
                'site_name' => 'Robins Computer',
                'site_legal_name' => 'Acme Tech Trading PLC',
                'sales_email' => 'hello@acme.test',
                'service_center_address' => '3 Repair Lane, Dhaka',
                'hotline_number' => '019000000',
                'hotline_hours' => '10:00 AM - 6:00 PM',
                'footer_note' => 'Assembled in Dhaka since 2009.',
            ],
        ])->assertStatus(200);

        SiteSetting::flushCache();

        $shared = SiteSetting::publicSettings();

        $this->assertSame('Acme Tech Trading PLC', $shared['site_legal_name']);
        $this->assertSame('hello@acme.test', $shared['sales_email']);
        $this->assertSame('3 Repair Lane, Dhaka', $shared['service_center_address']);
        $this->assertSame('019000000', $shared['hotline_number']);
        $this->assertSame('10:00 AM - 6:00 PM', $shared['hotline_hours']);
        $this->assertSame('Assembled in Dhaka since 2009.', $shared['footer_note']);

        $this->assertSame('Acme Tech Trading PLC', BrandDetails::legalName());
        $this->assertSame('019000000', BrandDetails::hotline());
    }

    /** Clearing the note means the footer ends after "All Rights Reserved". */
    public function test_the_footer_note_can_be_emptied(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/settings', [
            'settings' => ['footer_note' => ''],
        ])->assertStatus(200);

        SiteSetting::flushCache();

        $this->assertSame('', SiteSetting::publicSettings()['footer_note']);
    }

    public function test_the_home_page_carries_them(): void
    {
        SiteSetting::set('site_legal_name', 'Acme Tech Trading PLC');

        $props = $this->get('/')->assertStatus(200)->viewData('page')['props'];

        $this->assertSame('Acme Tech Trading PLC', $props['site_settings']['site_legal_name']);
    }
}
