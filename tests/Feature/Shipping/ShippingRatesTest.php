<?php

namespace Tests\Feature\Shipping;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\StockService;
use App\Support\ShippingRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Settings screen has collected delivery rates and a free-shipping
 * threshold for a long while and nothing read any of them — CartService
 * carried a hardcoded `const SHIPPING_FEE = 60.0` and charged it on every
 * order, so saving a rate changed nothing at all.
 */
class ShippingRatesTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Ryzen 5 7600',
            'slug' => 'ryzen-5-7600',
            'price' => 1000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        app(StockService::class)->receive([], [[
            'product_id' => $this->product->id,
            'quantity' => 100,
        ]]);
    }

    private function rates(array $settings): void
    {
        foreach ($settings as $key => $value) {
            SiteSetting::set($key, (string) $value, 'shipping');
        }

        SiteSetting::flushCache(array_keys($settings));
    }

    private function checkout(User $user, int $quantity, string $city): array
    {
        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product->id,
            'quantity' => $quantity,
        ])->assertStatus(200);

        return $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim',
            'phone' => '01712345678',
            'street_address' => 'House 45',
            'city' => $city,
        ])->assertStatus(201)->json('data');
    }

    public function test_the_default_applies_when_nothing_is_configured(): void
    {
        $order = $this->checkout(User::factory()->create(), 1, 'Dhaka');

        $this->assertSame(ShippingRates::DEFAULT_FEE, (float) $order['shipping_fee']);
    }

    public function test_the_configured_inside_dhaka_rate_is_charged(): void
    {
        $this->rates(['shipping_inside_dhaka' => 70, 'shipping_outside_dhaka' => 130]);

        $order = $this->checkout(User::factory()->create(), 1, 'Dhaka');

        $this->assertSame(70.0, (float) $order['shipping_fee']);
    }

    public function test_an_address_outside_dhaka_pays_the_higher_rate(): void
    {
        $this->rates(['shipping_inside_dhaka' => 70, 'shipping_outside_dhaka' => 130]);

        $order = $this->checkout(User::factory()->create(), 1, 'Chattogram');

        $this->assertSame(130.0, (float) $order['shipping_fee']);
    }

    /** A spelling should not cost a local customer the local rate. */
    public function test_dhaka_is_recognised_however_it_is_written(): void
    {
        foreach (['Dhaka', 'dhaka', 'DHAKA', 'Uttara, Dhaka', ' dhaka-1205 '] as $city) {
            $this->assertTrue(ShippingRates::isInsideDhaka($city), $city);
        }

        foreach (['Chattogram', 'Sylhet', 'Khulna', '', null] as $city) {
            $this->assertFalse(ShippingRates::isInsideDhaka($city), var_export($city, true));
        }
    }

    public function test_spending_past_the_threshold_ships_free(): void
    {
        $this->rates(['shipping_inside_dhaka' => 70, 'free_shipping_threshold' => 5000]);

        $order = $this->checkout(User::factory()->create(), 5, 'Dhaka');   // 5 x 1000

        $this->assertSame(0.0, (float) $order['shipping_fee']);
        $this->assertSame(5000.0, (float) $order['total']);
    }

    public function test_spending_below_the_threshold_still_pays(): void
    {
        $this->rates(['shipping_inside_dhaka' => 70, 'free_shipping_threshold' => 5000]);

        $order = $this->checkout(User::factory()->create(), 4, 'Dhaka');   // 4 x 1000

        $this->assertSame(70.0, (float) $order['shipping_fee']);
    }

    /** Zero and blank mean "no free delivery", not "everything ships free". */
    public function test_an_empty_threshold_does_not_make_everything_free(): void
    {
        foreach (['0', '', 'not a number'] as $value) {
            $this->rates(['free_shipping_threshold' => $value]);

            $this->assertNull(ShippingRates::freeThreshold(), var_export($value, true));
        }
    }

    /** The cart page has no address yet, so it quotes the local rate. */
    public function test_the_cart_quotes_the_inside_dhaka_rate(): void
    {
        $this->rates(['shipping_inside_dhaka' => 70, 'shipping_outside_dhaka' => 130]);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product->id,
            'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->getJson('/api/cart')
            ->assertStatus(200)
            ->assertJsonPath('data.totals.shipping_fee', 70);
    }

    /** The rate the order was charged is the one recorded against it. */
    public function test_the_charged_rate_is_stored_on_the_order(): void
    {
        $this->rates(['shipping_inside_dhaka' => 70, 'shipping_outside_dhaka' => 130]);

        $this->checkout(User::factory()->create(), 1, 'Rajshahi');

        $this->assertSame(130.0, (float) Order::first()->shipping_fee);
    }
}
