<?php

namespace Tests\Feature\Storefront;

use App\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reaching a brand's products from a brand's name.
 *
 * The homepage's brand tiles and the search box's brand pills both linked to
 * /shop?brand=<slug>, and the listing filters on brand_ids. The slug went
 * through as a filter that matched nothing, so every one of those links landed
 * on 0 of 9 — an empty shop rather than an unfiltered one, which is the worst
 * of the three possible outcomes and the hardest to notice, because a shop
 * with no results looks like a shop that is out of stock.
 *
 * The links carry ids now. This covers the ones already shared, bookmarked or
 * indexed, which still say brand.
 */
class ShopBrandFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_legacy_brand_slug_becomes_the_filter_the_shop_reads(): void
    {
        $brand = Brand::create(['name' => 'Dell', 'slug' => 'dell']);

        $this->get('/shop?brand=dell')
            ->assertRedirect('/shop?brand_ids='.$brand->id);
    }

    /** The whole shop is a better answer to a brand nobody stocks than none of it. */
    public function test_an_unknown_brand_drops_the_filter_rather_than_emptying_the_shop(): void
    {
        $this->get('/shop?brand=nosuchbrand')->assertRedirect('/shop');
    }

    /** Order is not part of the contract, so the parts are checked, not the string. */
    public function test_other_filters_survive_the_redirect(): void
    {
        $brand = Brand::create(['name' => 'Dell', 'slug' => 'dell']);

        $target = $this->get('/shop?brand=dell&sort=price_asc')
            ->assertStatus(302)
            ->headers->get('Location');

        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame((string) $brand->id, $query['brand_ids']);
        $this->assertSame('price_asc', $query['sort'], 'an unrelated filter was dropped');
        $this->assertArrayNotHasKey('brand', $query);
    }

    /** An explicit brand_ids wins; the redirect must not loop or override it. */
    public function test_it_leaves_an_explicit_filter_alone(): void
    {
        Brand::create(['name' => 'Dell', 'slug' => 'dell']);

        $this->get('/shop?brand_ids=99')->assertStatus(200);
    }

    public function test_the_plain_shop_is_untouched(): void
    {
        $this->get('/shop')->assertStatus(200);
    }
}
