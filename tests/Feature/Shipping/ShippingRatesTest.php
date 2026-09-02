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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Checking out with one address line and a stated zone.
     *
     * @param  string  $zone  what the customer chose
     * @param  float  $expected  what they should be charged
     */
    #[DataProvider('statedZones')]
    public function test_the_zone_the_customer_states_sets_the_rate(string $zone, float $expected): void
    {
        $this->rates(['shipping_inside_dhaka' => 70, 'shipping_outside_dhaka' => 130]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product->id,
            'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim',
            'phone' => '01712345678',
            'address' => 'House 12, Road 5, Dhanmondi',
            'delivery_zone' => $zone,
        ])->assertStatus(201);

        $this->assertSame($expected, (float) Order::first()->shipping_fee);
    }

    public static function statedZones(): array
    {
        return [
            'inside Dhaka' => [ShippingRates::ZONE_INSIDE_DHAKA, 70.0],
            'outside Dhaka' => [ShippingRates::ZONE_OUTSIDE_DHAKA, 130.0],
        ];
    }

    /**
     * The whole reason the zone is asked for rather than read.
     *
     * An address on one line can say "Dhaka" while being nowhere near it. Under
     * the old rule — search the city for "dhaka" — this address was charged the
     * local rate; the customer's stated zone now settles it.
     */
    public function test_an_address_naming_dhaka_outside_dhaka_pays_the_outside_rate(): void
    {
        $this->rates(['shipping_inside_dhaka' => 70, 'shipping_outside_dhaka' => 130]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product->id,
            'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim',
            'phone' => '01712345678',
            'address' => 'Dhaka Road, Feni Sadar, Feni',
            'delivery_zone' => ShippingRates::ZONE_OUTSIDE_DHAKA,
        ])->assertStatus(201);

        $this->assertSame(130.0, (float) Order::first()->shipping_fee);
    }

    /** The choice is kept on the order, so re-pricing an edit agrees with it. */
    public function test_the_zone_is_recorded_on_the_order(): void
    {
        $this->rates(['shipping_inside_dhaka' => 70, 'shipping_outside_dhaka' => 130]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product->id,
            'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim',
            'phone' => '01712345678',
            'address' => 'House 12, Dhanmondi',
            'delivery_zone' => ShippingRates::ZONE_INSIDE_DHAKA,
        ])->assertStatus(201);

        $this->assertSame(
            ShippingRates::ZONE_INSIDE_DHAKA,
            Order::first()->shipping_address['delivery_zone']
        );
    }

    /** An order placed before the zone was asked for still prices from its city. */
    public function test_an_order_without_a_zone_still_prices_from_the_city(): void
    {
        $this->rates(['shipping_inside_dhaka' => 70, 'shipping_outside_dhaka' => 130]);

        $this->assertSame(130.0, ShippingRates::feeFor('Rajshahi', 1000.0, null));
        $this->assertSame(70.0, ShippingRates::feeFor('Dhaka', 1000.0, null));
    }

    /** A zone we do not recognise is not trusted; the city decides instead. */
    public function test_an_unknown_zone_falls_back_to_the_city(): void
    {
        $this->rates(['shipping_inside_dhaka' => 70, 'shipping_outside_dhaka' => 130]);

        $this->assertSame(130.0, ShippingRates::feeFor('Rajshahi', 1000.0, 'somewhere_else'));
    }

    /** Free delivery outranks the zone, whichever one was chosen. */
    public function test_the_free_threshold_still_wins_over_a_stated_zone(): void
    {
        $this->rates([
            'shipping_inside_dhaka' => 70,
            'shipping_outside_dhaka' => 130,
            'free_shipping_threshold' => 5000,
        ]);

        $this->assertSame(
            0.0,
            ShippingRates::feeFor(null, 6000.0, ShippingRates::ZONE_OUTSIDE_DHAKA)
        );
    }
}
