<?php

namespace Tests\Feature\Delivery;

use App\Exceptions\CourierException;
use App\Models\Category;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Booking the parcel with the carrier rather than typing its number in.
 *
 * The carriers' own APIs cannot be exercised here — they need a merchant
 * account and live keys — so these pin the parts that are ours: that a booking
 * is attempted before the order moves, that a refusal leaves the order where it
 * was, that the returned consignment number is the one stored, and that a
 * carrier without credentials still dispatches by hand.
 *
 * The request bodies asserted below are also the record of what each driver
 * sends, so a change to a carrier's contract shows up here.
 */
class CourierBookingTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);

        $this->product = Product::create([
            'category_id' => $category->id, 'name' => 'Ryzen', 'slug' => 'ryzen',
            'price' => 1000, 'stock_quantity' => 0, 'is_active' => true,
        ]);

        app(StockService::class)->receive([], [[
            'product_id' => $this->product->id, 'quantity' => 50,
        ]]);
    }

    private function order(): Order
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product->id, 'quantity' => 2,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        return Order::latest('id')->first();
    }

    private function courier(string $slug, array $credentials = []): Courier
    {
        $courier = Courier::where('slug', $slug)->first();
        $courier->update(['credentials' => $credentials]);

        return $courier->fresh();
    }

    // ── Steadfast ───────────────────────────────────────────────────────────

    public function test_steadfast_books_the_parcel_and_stores_its_tracking_code(): void
    {
        Http::fake(['portal.packzy.com/*' => Http::response([
            'status' => 200,
            'consignment' => ['consignment_id' => 1234, 'tracking_code' => 'ABC123XY'],
        ])]);

        $order = $this->order();
        $courier = $this->courier('steadfast', ['api_key' => 'k', 'secret_key' => 's']);

        app(OrderService::class)->dispatchOrder($order, $courier);
        $order->refresh();

        $this->assertSame('ABC123XY', $order->tracking_number);
        $this->assertSame('shipped', $order->status);
        $this->assertSame('https://steadfast.com.bd/t/ABC123XY', $order->tracking_url);
    }

    /** What the rider must collect, which is the expensive field to get wrong. */
    public function test_steadfast_is_told_the_cash_to_collect(): void
    {
        Http::fake(['portal.packzy.com/*' => Http::response([
            'consignment' => ['tracking_code' => 'T1'],
        ])]);

        $order = $this->order();
        app(OrderService::class)->dispatchOrder(
            $order, $this->courier('steadfast', ['api_key' => 'k', 'secret_key' => 's'])
        );

        Http::assertSent(function ($request) use ($order) {
            return $request['cod_amount'] === (float) $order->total
                && $request['invoice'] === $order->order_number
                && $request['recipient_phone'] === '01712345678'
                && $request->hasHeader('Api-Key', 'k');
        });
    }

    /** Nothing to collect on an order already paid for. */
    public function test_a_prepaid_order_collects_nothing_on_delivery(): void
    {
        Http::fake(['portal.packzy.com/*' => Http::response(['consignment' => ['tracking_code' => 'T2']])]);

        $order = $this->order();
        $order->update(['payment_status' => 'paid']);

        app(OrderService::class)->dispatchOrder(
            $order->fresh(), $this->courier('steadfast', ['api_key' => 'k', 'secret_key' => 's'])
        );

        Http::assertSent(fn ($request) => $request['cod_amount'] === 0);
    }

    /**
     * The order must not move when the carrier says no. Telling a customer
     * their parcel is on its way when nobody has it is the worse failure.
     */
    public function test_a_refusal_leaves_the_order_unshipped(): void
    {
        Http::fake(['portal.packzy.com/*' => Http::response([
            'message' => 'Recipient phone is invalid',
        ], 422)]);

        $order = $this->order();
        $courier = $this->courier('steadfast', ['api_key' => 'k', 'secret_key' => 's']);

        try {
            app(OrderService::class)->dispatchOrder($order, $courier);
            $this->fail('A refused booking still shipped the order.');
        } catch (CourierException $e) {
            $this->assertStringContainsString('Recipient phone is invalid', $e->getMessage());
        }

        $order->refresh();

        $this->assertSame('pending', $order->status);
        $this->assertNull($order->tracking_number);
        $this->assertNull($order->dispatched_at);
    }

    public function test_an_unreachable_carrier_leaves_the_order_unshipped(): void
    {
        Http::fake(fn () => throw new \RuntimeException('Connection timed out'));

        $order = $this->order();
        $courier = $this->courier('steadfast', ['api_key' => 'k', 'secret_key' => 's']);

        try {
            app(OrderService::class)->dispatchOrder($order, $courier);
            $this->fail('An unreachable carrier still shipped the order.');
        } catch (CourierException $e) {
            $this->assertStringContainsString('has not been marked shipped', $e->getMessage());
        }

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** A booking that succeeds but returns nothing is not a success. */
    public function test_a_booking_with_no_number_back_is_treated_as_a_refusal(): void
    {
        Http::fake(['portal.packzy.com/*' => Http::response(['consignment' => []])]);

        $order = $this->order();

        $this->expectException(CourierException::class);
        app(OrderService::class)->dispatchOrder(
            $order, $this->courier('steadfast', ['api_key' => 'k', 'secret_key' => 's'])
        );
    }

    // ── Pathao ──────────────────────────────────────────────────────────────

    public function test_pathao_authenticates_then_books(): void
    {
        Http::fake([
            '*/issue-token' => Http::response(['access_token' => 'tok-123']),
            '*/orders' => Http::response(['data' => ['consignment_id' => 'DH240827XYZ']]),
        ]);

        $order = $this->order();
        $courier = $this->courier('pathao', [
            'client_id' => 'cid', 'client_secret' => 'sec',
            'username' => 'shop@example.com', 'password' => 'pw',
            'store_id' => '77', 'default_city_id' => '1', 'default_zone_id' => '52',
        ]);

        app(OrderService::class)->dispatchOrder($order, $courier);

        $this->assertSame('DH240827XYZ', $order->fresh()->tracking_number);
        $this->assertSame(
            'https://merchant.pathao.com/tracking?consignment_id=DH240827XYZ',
            $order->fresh()->tracking_url
        );

        // The booking carried the token from the first call.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/orders')
            && $r->hasHeader('Authorization', 'Bearer tok-123'));
    }

    /** Pathao refuses a booking with no zone, so it is caught before the call. */
    public function test_pathao_says_what_is_missing_rather_than_failing_at_their_end(): void
    {
        Http::fake(['*/issue-token' => Http::response(['access_token' => 'tok'])]);

        $order = $this->order();
        $courier = $this->courier('pathao', [
            'client_id' => 'cid', 'client_secret' => 'sec',
            'username' => 'u', 'password' => 'p', 'store_id' => '77',
        ]);

        try {
            app(OrderService::class)->dispatchOrder($order, $courier);
            $this->fail('A booking with no zone was attempted.');
        } catch (CourierException $e) {
            $this->assertStringContainsString('city and zone', $e->getMessage());
        }

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_pathao_rejects_bad_credentials_clearly(): void
    {
        Http::fake(['*/issue-token' => Http::response(['message' => 'invalid_client'], 401)]);

        $order = $this->order();
        $courier = $this->courier('pathao', [
            'client_id' => 'wrong', 'client_secret' => 'wrong',
            'username' => 'u', 'password' => 'p', 'store_id' => '1',
            'default_city_id' => '1', 'default_zone_id' => '2',
        ]);

        try {
            app(OrderService::class)->dispatchOrder($order, $courier);
            $this->fail('Bad credentials still dispatched.');
        } catch (CourierException $e) {
            $this->assertStringContainsString('credentials were not accepted', $e->getMessage());
        }
    }

    // ── RedX ────────────────────────────────────────────────────────────────

    public function test_redx_books_the_parcel(): void
    {
        Http::fake(['openapi.redx.com.bd/*' => Http::response(['tracking_id' => '25AUG27RX'])]);

        $order = $this->order();
        $courier = $this->courier('redx', [
            'access_token' => 'tok', 'default_area_id' => '1', 'pickup_store_id' => '9',
        ]);

        app(OrderService::class)->dispatchOrder($order, $courier);

        $this->assertSame('25AUG27RX', $order->fresh()->tracking_number);
        Http::assertSent(fn ($r) => $r->hasHeader('API-ACCESS-TOKEN', 'Bearer tok'));
    }

    // ── Falling back ────────────────────────────────────────────────────────

    /**
     * A carrier with a driver but no keys must still dispatch. Losing the
     * ability to send parcels because a key expired would be worse than the
     * problem the integration solves.
     */
    public function test_a_carrier_without_credentials_falls_back_to_a_typed_number(): void
    {
        $order = $this->order();
        $courier = Courier::where('slug', 'steadfast')->first();

        $this->assertFalse($courier->canBook());

        app(OrderService::class)->dispatchOrder($order, $courier, 'TYPED-123');

        $this->assertSame('TYPED-123', $order->fresh()->tracking_number);
        $this->assertSame('shipped', $order->fresh()->status);
        // No call was made, because there was nothing to authenticate with.
        Http::assertNothingSent();
    }

    public function test_a_manual_carrier_never_calls_anything(): void
    {
        $order = $this->order();
        $courier = Courier::where('slug', 'sundarban')->first();

        app(OrderService::class)->dispatchOrder($order, $courier, 'SBN-1');

        $this->assertSame('SBN-1', $order->fresh()->tracking_number);
        Http::assertNothingSent();
    }

    // ── Credentials ─────────────────────────────────────────────────────────

    /** They are keys to a paid account, so they are encrypted at rest. */
    public function test_credentials_are_encrypted_in_the_database(): void
    {
        $courier = $this->courier('steadfast', ['api_key' => 'super-secret-key']);

        $stored = DB::table('couriers')
            ->where('id', $courier->id)->value('credentials');

        $this->assertStringNotContainsString('super-secret-key', (string) $stored);
        $this->assertSame('super-secret-key', $courier->fresh()->credentials['api_key']);
    }

    /** And they must never travel to the browser. */
    public function test_credentials_never_reach_the_admin_screen(): void
    {
        $this->courier('steadfast', ['api_key' => 'super-secret-key']);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/couriers')->assertStatus(200);

        $response->assertDontSee('super-secret-key');

        $couriers = collect($response->viewData('page')['props']['couriers']);
        $steadfast = $couriers->firstWhere('slug', 'steadfast');

        $this->assertArrayNotHasKey('credentials', $steadfast);
        $this->assertTrue($steadfast['has_credentials']);
    }
}
