<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * /track/{orderNumber} names the order in the address bar.
 *
 * It is a convenience, not a key. The order number fills in the first box and
 * nothing else: the phone number is still what proves the order is yours, so a
 * link that gets forwarded shows a form rather than somebody's name, number
 * and street address.
 */
class TrackByUrlTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = 'ORD-PZDNC5IATQ';

    private function order(): Order
    {
        return Order::create([
            'order_number' => self::NUMBER,
            'user_id' => null,
            'session_id' => str_repeat('a', 40),
            'status' => 'pending',
            'subtotal' => 1000,
            'shipping_fee' => 60,
            'discount' => 0,
            'total' => 1060,
            'payment_method' => 'COD',
            'payment_status' => 'pending',
            'shipping_address' => [
                'name' => 'Rahim Chowdhury',
                'phone' => '01711000000',
                'city' => 'Dhaka',
                'street_address' => 'House 12, Road 4',
            ],
        ]);
    }

    public function test_the_bare_page_has_no_order_in_it(): void
    {
        $props = $this->get('/track')->assertStatus(200)->viewData('page')['props'];

        $this->assertNull($props['orderNumber']);
    }

    public function test_the_order_number_reaches_the_page(): void
    {
        $this->order();

        $props = $this->get('/track/'.self::NUMBER)
            ->assertStatus(200)
            ->viewData('page')['props'];

        $this->assertSame(self::NUMBER, $props['orderNumber']);
    }

    /** Order numbers are stored uppercase; a typed link should still work. */
    public function test_a_lowercase_link_is_upper_cased(): void
    {
        $props = $this->get('/track/ord-pzdnc5iatq')
            ->assertStatus(200)
            ->viewData('page')['props'];

        $this->assertSame(self::NUMBER, $props['orderNumber']);
    }

    /**
     * The whole point of the phone check.
     *
     * A forwarded link, a browser history someone else reads, a URL in an
     * analytics log — none of them should hand over the customer's details.
     */
    public function test_a_link_alone_reveals_nothing_about_the_order(): void
    {
        $this->order();

        $response = $this->get('/track/'.self::NUMBER)->assertStatus(200);
        $props = $response->viewData('page')['props'];

        // The page is handed the number it was asked for and nothing else.
        $this->assertArrayNotHasKey('order', $props);
        $this->assertArrayNotHasKey('trackingResult', $props);
        $this->assertArrayNotHasKey('shipping_address', $props);

        $response->assertDontSee('Rahim Chowdhury')
            ->assertDontSee('House 12, Road 4')
            ->assertDontSee('01711000000');
    }

    /**
     * An order number that exists and one that does not look the same.
     *
     * Otherwise the URL becomes a way to find out which order numbers are
     * real, which is the thing the tracking endpoint already refuses to say.
     */
    public function test_a_real_and_an_invented_order_number_are_indistinguishable(): void
    {
        $this->order();

        $real = $this->get('/track/'.self::NUMBER)->assertStatus(200);
        $invented = $this->get('/track/ORD-DOESNOTEXIST')->assertStatus(200);

        $this->assertSame(
            $real->viewData('page')['component'],
            $invented->viewData('page')['component']
        );
    }

    /**
     * The hash is decoration, not part of the number.
     *
     * Six screens print it as "#ORD-ABC123" — the confirmation page they land
     * on after paying among them — so that hash is exactly what gets copied.
     */
    public function test_a_copied_hash_is_ignored(): void
    {
        $this->order();

        foreach (['#'.self::NUMBER, ' #'.self::NUMBER.' ', '#ord-pzdnc5iatq'] as $typed) {
            $this->postJson('/api/orders/track', [
                'order_number' => $typed,
                'phone' => '01711000000',
            ])->assertSuccessful()->assertJsonPath('data.order_number', self::NUMBER);
        }
    }

    /** A hash survives in a URL only when encoded; unencoded it is a fragment. */
    public function test_an_encoded_hash_in_the_url_is_ignored(): void
    {
        $props = $this->get('/track/%23'.self::NUMBER)
            ->assertStatus(200)
            ->viewData('page')['props'];

        $this->assertSame(self::NUMBER, $props['orderNumber']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function writtenNumbers(): array
    {
        return [
            'as stored' => ['ORD-ABC123', 'ORD-ABC123'],
            'as printed' => ['#ORD-ABC123', 'ORD-ABC123'],
            'lowercase' => ['ord-abc123', 'ORD-ABC123'],
            'pasted with spaces' => ['  ORD-ABC123 ', 'ORD-ABC123'],
            'hash and spaces' => [' # ORD-ABC123 ', 'ORD-ABC123'],
            'nothing' => ['', ''],
            'only a hash' => ['#', ''],
        ];
    }

    #[DataProvider('writtenNumbers')]
    public function test_an_order_number_reduces_to_one_form(string $typed, string $expected): void
    {
        $this->assertSame($expected, Order::normalizeNumber($typed));
    }

    public function test_the_route_turns_away_what_cannot_be_an_order_number(): void
    {
        $this->get('/track/'.str_repeat('A', 65))->assertStatus(404);
        $this->get('/track/has spaces')->assertStatus(404);
        $this->get('/track/slash/es')->assertStatus(404);
    }

    /**
     * Signing in is proof enough for your own order.
     *
     * An account is not required to carry a phone number — registering with an
     * email and leaving it blank is allowed — so that customer could otherwise
     * never track an order they had placed.
     */
    public function test_a_signed_in_customer_opens_their_own_order_without_a_phone(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'phone' => null]);

        $order = $this->order();
        $order->forceFill(['user_id' => $customer->id])->save();

        $this->actingAs($customer)
            ->postJson('/api/orders/track', ['order_number' => self::NUMBER])
            ->assertSuccessful()
            ->assertJsonPath('data.order_number', self::NUMBER);
    }

    /** Signing in is not a skeleton key. */
    public function test_signing_in_does_not_open_somebody_elses_order(): void
    {
        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'phone' => null]);

        $owner = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = $this->order();
        $order->forceFill(['user_id' => $owner->id])->save();

        $this->actingAs($stranger)
            ->postJson('/api/orders/track', ['order_number' => self::NUMBER])
            ->assertStatus(404);

        // Nor with a phone that is not the one on the order.
        $this->actingAs($stranger)
            ->postJson('/api/orders/track', [
                'order_number' => self::NUMBER,
                'phone' => '01998887777',
            ])->assertStatus(404);
    }

    /** A guest order tracked by the customer who is signed in, using the phone. */
    public function test_a_signed_in_customer_still_needs_the_phone_for_a_guest_order(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'phone' => null]);

        // Placed before they had an account, so it carries no user_id.
        $this->order();

        $this->actingAs($customer)
            ->postJson('/api/orders/track', ['order_number' => self::NUMBER])
            ->assertStatus(404);

        $this->actingAs($customer)
            ->postJson('/api/orders/track', [
                'order_number' => self::NUMBER,
                'phone' => '01711000000',
            ])->assertSuccessful();
    }

    /** A guest is still asked for it. */
    public function test_a_guest_must_give_a_phone(): void
    {
        $this->order();

        $this->postJson('/api/orders/track', ['order_number' => self::NUMBER])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    /** The phone still does the work, whatever the URL said. */
    public function test_the_lookup_itself_is_unchanged(): void
    {
        $this->order();

        $this->postJson('/api/orders/track', [
            'order_number' => self::NUMBER,
            'phone' => '01998887777',
        ])->assertStatus(404);

        $this->postJson('/api/orders/track', [
            'order_number' => self::NUMBER,
            'phone' => '01711000000',
        ])->assertSuccessful()->assertJsonPath('data.order_number', self::NUMBER);
    }
}
