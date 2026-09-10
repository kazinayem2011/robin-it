<?php

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\ShippingRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an order says it costs, and whether the parts add up to it.
 *
 * The guards around placing one are covered — stock, options, delisted
 * products, two shoppers reaching for the last unit — and the arithmetic
 * underneath is not. It is also the part a customer checks: a delivery fee
 * that quietly differs from the one quoted, or a total that is not its own
 * lines, is the kind of thing they notice and the shop does not.
 */
class OrderTotalsTest extends TestCase
{
    use RefreshDatabase;

    private function product(float $price, int $stock = 50): Product
    {
        $shelf = Category::firstOrCreate(
            ['slug' => 'laptop'],
            ['name' => 'Laptop', 'is_active' => true],
        );

        $product = Product::create([
            'name' => 'Item '.uniqid(),
            'slug' => 'item-'.uniqid(),
            'category_id' => $shelf->id,
            'price' => $price,
            'stock_quantity' => $stock,
            'is_active' => true,
        ]);

        $product->categories()->syncWithoutDetaching([$shelf->id]);

        return $product;
    }

    private function checkout(array $lines, array $overrides = []): array
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']));

        foreach ($lines as [$product, $quantity]) {
            $this->postJson('/api/cart', [
                'product_id' => $product->id,
                'quantity' => $quantity,
            ])->assertSuccessful();
        }

        $response = $this->postJson('/api/checkout', array_merge([
            'name' => 'A Customer',
            'phone' => '01711111111',
            'address' => '10 Some Road',
            'delivery_zone' => ShippingRates::ZONE_INSIDE_DHAKA,
            'payment_method' => 'COD',
        ], $overrides));

        $response->assertSuccessful();

        return [$response, Order::latest('id')->firstOrFail()];
    }

    public function test_the_subtotal_is_the_lines_added_up(): void
    {
        [, $order] = $this->checkout([
            [$this->product(1000), 2],
            [$this->product(250), 3],
        ]);

        $this->assertEqualsWithDelta(2750, (float) $order->subtotal, 0.01);
        $this->assertEqualsWithDelta(
            (float) $order->subtotal,
            $order->items->sum(fn ($item) => (float) $item->total),
            0.01,
        );
    }

    /** Whatever the parts are, the total is their sum. */
    public function test_the_total_is_its_own_parts(): void
    {
        [, $order] = $this->checkout([[$this->product(1500), 2]]);

        $this->assertEqualsWithDelta(
            (float) $order->subtotal
                + (float) $order->shipping_fee
                + (float) $order->vat_amount
                - (float) $order->discount,
            (float) $order->total,
            0.01,
        );
    }

    // ── delivery ─────────────────────────────────────────────────────────────

    /**
     * Both rates fall back to the same default, so a shop that has not set
     * them charges one price everywhere. These set them apart first —
     * otherwise the two tests below pass without the zone being read at all.
     */
    private function chargeDifferentRates(): void
    {
        SiteSetting::set('shipping_inside_dhaka', '60');
        SiteSetting::set('shipping_outside_dhaka', '130');
    }

    public function test_delivery_inside_dhaka_is_charged_at_the_local_rate(): void
    {
        $this->chargeDifferentRates();

        [, $order] = $this->checkout([[$this->product(500), 1]], [
            'delivery_zone' => ShippingRates::ZONE_INSIDE_DHAKA,
        ]);

        $this->assertEqualsWithDelta(
            ShippingRates::insideDhaka(),
            (float) $order->shipping_fee,
            0.01,
        );
    }

    public function test_delivery_outside_dhaka_is_charged_at_the_other_rate(): void
    {
        $this->chargeDifferentRates();

        [, $order] = $this->checkout([[$this->product(500), 1]], [
            'delivery_zone' => ShippingRates::ZONE_OUTSIDE_DHAKA,
        ]);

        $this->assertEqualsWithDelta(
            ShippingRates::outsideDhaka(),
            (float) $order->shipping_fee,
            0.01,
        );
    }

    /**
     * The zone the customer chose, not the one the cart guessed. The cart page
     * has no address yet and quotes the local rate; the address is what the
     * order is charged against.
     */
    public function test_the_order_charges_the_zone_that_was_chosen(): void
    {
        $this->chargeDifferentRates();

        [$response, $order] = $this->checkout([[$this->product(500), 1]], [
            'delivery_zone' => ShippingRates::ZONE_OUTSIDE_DHAKA,
        ]);

        $this->assertEqualsWithDelta(
            (float) $order->shipping_fee,
            (float) $response->json('data.shipping_fee'),
            0.01,
        );
        $this->assertNotEqualsWithDelta(
            ShippingRates::insideDhaka(),
            (float) $order->shipping_fee,
            0.01,
        );
    }

    /** Above the threshold the shop carries it, if the shop sets one. */
    public function test_a_basket_over_the_free_threshold_is_delivered_free(): void
    {
        SiteSetting::set('free_shipping_threshold', '5000');

        [, $order] = $this->checkout([[$this->product(6000), 1]]);

        $this->assertEqualsWithDelta(0, (float) $order->shipping_fee, 0.01);
        $this->assertEqualsWithDelta(
            (float) $order->subtotal,
            (float) $order->total,
            0.01,
        );
    }

    // ── what the customer may not decide ─────────────────────────────────────

    /**
     * The posted figures are ignored. Everything about what an order costs is
     * worked out from the cart on the server, so a client that sends its own
     * total is describing a wish.
     */
    public function test_a_posted_total_is_ignored(): void
    {
        [, $order] = $this->checkout([[$this->product(1000), 2]], [
            'total' => 1,
            'subtotal' => 1,
            'shipping_fee' => 0,
            'discount' => 9999,
        ]);

        $this->assertEqualsWithDelta(2000, (float) $order->subtotal, 0.01);
        $this->assertEqualsWithDelta(0, (float) $order->discount, 0.01);
        $this->assertGreaterThan(1, (float) $order->total);
    }

    /** A line records what it was sold for, not what the product costs now. */
    public function test_a_line_keeps_the_price_it_was_sold_at(): void
    {
        $product = $this->product(1200);

        [, $order] = $this->checkout([[$product, 1]]);

        $product->update(['price' => 9999]);

        $line = $order->items()->first();

        $this->assertEqualsWithDelta(1200, (float) $line->price, 0.01);
        $this->assertEqualsWithDelta(1200, (float) $order->fresh()->subtotal, 0.01);
    }
}
