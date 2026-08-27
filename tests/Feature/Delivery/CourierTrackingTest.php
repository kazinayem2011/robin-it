<?php

namespace Tests\Feature\Delivery;

use App\Models\Category;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An order could be marked shipped and that was the end of it — no carrier, no
 * consignment number, and nothing to tell a customer ringing up to ask where
 * their delivery was.
 */
class CourierTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

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
            'product_id' => $this->product->id, 'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        return Order::latest('id')->first();
    }

    /** The carriers a Bangladeshi shop is most likely to use come seeded. */
    public function test_the_popular_bangladeshi_couriers_are_seeded(): void
    {
        $slugs = Courier::pluck('slug')->all();

        foreach (['pathao', 'steadfast', 'redx', 'paperfly', 'ecourier', 'sundarban', 'sa-paribahan'] as $expected) {
            $this->assertContains($expected, $slugs);
        }

        // A shop delivering with its own rider still needs something to pick.
        $this->assertContains('own-delivery', $slugs);
    }

    public function test_dispatching_records_the_carrier_and_ships_the_order(): void
    {
        $order = $this->order();
        $courier = Courier::where('slug', 'steadfast')->first();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->patchJson("/api/admin/orders/{$order->id}/dispatch", [
                'courier_id' => $courier->id,
                'tracking_number' => 'SF123456789',
            ])->assertStatus(200);

        $order->refresh();

        $this->assertSame('shipped', $order->status);
        $this->assertSame($courier->id, $order->courier_id);
        $this->assertSame('SF123456789', $order->tracking_number);
        $this->assertNotNull($order->dispatched_at);
        $this->assertTrue($order->isDispatched());
    }

    public function test_the_tracking_link_is_built_from_the_consignment_number(): void
    {
        $order = $this->order();
        $courier = Courier::where('slug', 'steadfast')->first();

        app(OrderService::class)->dispatchOrder($order, $courier, 'SF123456789');

        $this->assertSame('https://steadfast.com.bd/t/SF123456789', $order->fresh()->tracking_url);
    }

    /** A number with a space or a slash must not break the URL. */
    public function test_the_consignment_number_is_encoded_into_the_link(): void
    {
        $order = $this->order();
        $courier = Courier::where('slug', 'steadfast')->first();

        app(OrderService::class)->dispatchOrder($order, $courier, 'SF 123/456');

        $this->assertSame('https://steadfast.com.bd/t/SF%20123%2F456', $order->fresh()->tracking_url);
    }

    /**
     * Some carriers have no per-parcel page. That is a real answer, not a
     * failure: the number is still recorded and worth quoting down the phone.
     */
    public function test_a_courier_without_a_lookup_still_records_the_number(): void
    {
        $order = $this->order();
        $courier = Courier::where('slug', 'sundarban')->first();

        app(OrderService::class)->dispatchOrder($order, $courier, 'SBN-99881');

        $order->refresh();

        $this->assertSame('SBN-99881', $order->tracking_number);
        $this->assertNull($order->tracking_url);
    }

    /** A shop's own rider has no consignment number at all. */
    public function test_a_tracking_number_is_optional(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->patchJson("/api/admin/orders/{$order->id}/dispatch", [
                'courier_id' => Courier::where('slug', 'own-delivery')->value('id'),
            ])->assertStatus(200);

        $order->refresh();

        $this->assertSame('shipped', $order->status);
        $this->assertNull($order->tracking_number);
    }

    public function test_a_courier_must_be_named(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->patchJson("/api/admin/orders/{$order->id}/dispatch", ['tracking_number' => 'X1'])
            ->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** Dispatching twice must not move the date it actually left. */
    public function test_redispatching_keeps_the_original_departure(): void
    {
        $order = $this->order();
        $orders = app(OrderService::class);

        $orders->dispatchOrder($order, Courier::where('slug', 'redx')->first(), 'RX1');
        $left = $order->fresh()->dispatched_at;

        $orders->dispatchOrder($order->fresh(), Courier::where('slug', 'pathao')->first(), 'PT2');
        $again = $order->fresh();

        $this->assertEquals($left->timestamp, $again->dispatched_at->timestamp);
        // But a corrected carrier and number do take.
        $this->assertSame('PT2', $again->tracking_number);
    }

    public function test_a_finished_order_cannot_be_dispatched(): void
    {
        $order = $this->order();
        $orders = app(OrderService::class);
        $orders->updateOrderStatus($order, 'cancelled');

        $this->expectExceptionMessage('can no longer be dispatched');
        $orders->dispatchOrder($order->fresh(), Courier::first());
    }

    /** The question the track page exists to answer. */
    public function test_order_tracking_tells_the_customer_who_has_the_parcel(): void
    {
        $order = $this->order();
        app(OrderService::class)->dispatchOrder(
            $order, Courier::where('slug', 'pathao')->first(), 'PT99887'
        );

        $this->postJson('/api/orders/track', [
            'order_number' => $order->order_number,
            'phone' => '01712345678',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.courier', 'Pathao Courier')
            ->assertJsonPath('data.tracking_number', 'PT99887')
            ->assertJsonPath(
                'data.tracking_url',
                'https://merchant.pathao.com/tracking?consignment_id=PT99887'
            );
    }

    public function test_a_customer_cannot_dispatch_their_own_order(): void
    {
        $order = $this->order();
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)
            ->patchJson("/api/admin/orders/{$order->id}/dispatch", [
                'courier_id' => Courier::first()->id,
            ])->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** Carriers change their URLs; correcting one must not need a deploy. */
    public function test_a_tracking_link_can_be_corrected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $courier = Courier::where('slug', 'redx')->first();

        $this->actingAs($admin)
            ->patchJson("/api/admin/couriers/{$courier->id}", [
                'name' => $courier->name,
                'tracking_url_template' => 'https://redx.com.bd/parcel/{tracking}',
            ])->assertStatus(200);

        $this->assertSame(
            'https://redx.com.bd/parcel/RX55',
            $courier->fresh()->trackingUrlFor('RX55')
        );
    }

    /** A link with no placeholder is nearly always a mistake worth catching. */
    public function test_a_tracking_link_without_the_placeholder_is_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->patchJson('/api/admin/couriers/'.Courier::first()->id, [
                'name' => 'Pathao Courier',
                'tracking_url_template' => 'https://merchant.pathao.com/tracking',
            ])->assertStatus(422);
    }

    /** A carrier that has carried parcels is hidden, so old orders still name it. */
    public function test_a_used_courier_is_hidden_rather_than_deleted(): void
    {
        $order = $this->order();
        $courier = Courier::where('slug', 'redx')->first();
        app(OrderService::class)->dispatchOrder($order, $courier, 'RX1');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->deleteJson("/api/admin/couriers/{$courier->id}")->assertStatus(200);

        $this->assertFalse($courier->fresh()->is_active);
        $this->assertSame('RedX', $order->fresh()->courier->name);
    }
}
