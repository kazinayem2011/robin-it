<?php

namespace Tests\Feature\Admin;

use App\Models\SiteSetting;
use App\Support\BrandDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where the shop's name comes from.
 *
 * Site Settings is the authority, because that is the field an admin actually
 * edits. APP_NAME is only the fallback for an install where nobody has set one,
 * and it used to be appended to every page title on top of the real name — so
 * every browser tab read the brand twice, the second time as "Laravel".
 */
class BrandNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_setting_is_what_the_shop_is_called(): void
    {
        config(['app.name' => 'Electronics Store']);
        SiteSetting::set('site_name', 'Robins Computer');

        $this->assertSame('Robins Computer', BrandDetails::name());
    }

    public function test_it_falls_back_to_app_name_when_nobody_has_set_one(): void
    {
        config(['app.name' => 'Electronics Store']);
        SiteSetting::flushCache(['site_name']);

        $this->assertSame('Electronics Store', BrandDetails::name());
    }

    /** A row saved as an empty string is the same as never having set one. */
    public function test_a_blank_setting_falls_back_too(): void
    {
        config(['app.name' => 'Electronics Store']);
        SiteSetting::set('site_name', '   ');

        $this->assertSame('Electronics Store', BrandDetails::name());
    }

    public function test_renaming_the_shop_takes_effect_immediately(): void
    {
        SiteSetting::set('site_name', 'First Name');
        $this->assertSame('First Name', BrandDetails::name());

        SiteSetting::set('site_name', 'Second Name');
        $this->assertSame('Second Name', BrandDetails::name());
    }

    /** Every page title is built from this, so it has to reach the browser. */
    public function test_the_resolved_name_is_shared_with_every_page(): void
    {
        SiteSetting::set('site_name', 'Robins Computer');

        $response = $this->get('/');
        $response->assertStatus(200);

        $this->assertSame(
            'Robins Computer',
            $response->viewData('page')['props']['brand_name']
        );
    }

    public function test_the_shared_name_survives_the_setting_being_absent(): void
    {
        config(['app.name' => 'Electronics Store']);
        SiteSetting::where('key', 'site_name')->delete();
        SiteSetting::flushCache(['site_name']);

        $response = $this->get('/');

        $this->assertSame(
            'Electronics Store',
            $response->viewData('page')['props']['brand_name']
        );
    }

    /** Mail and invoices read the same accessor, not their own copy. */
    public function test_outgoing_mail_uses_the_same_name(): void
    {
        SiteSetting::set('site_name', 'Robins Computer');

        $this->assertSame('Robins Computer', BrandDetails::all()['name']);
    }
}
