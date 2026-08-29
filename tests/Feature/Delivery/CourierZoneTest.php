<?php

namespace Tests\Feature\Delivery;

use App\Models\Courier;
use App\Models\CourierZone;
use App\Models\Order;
use App\Models\User;
use App\Services\Courier\ZoneResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Turning a written address into the ids a courier books against.
 *
 * Pathao and RedX take numbers from their own area lists, not addresses.
 * Nothing mapped them, so every parcel went out on the single default zone
 * saved with the credentials — correct for the shop's own district and wrong
 * for the other sixty-three, and wrong invisibly: it books successfully and
 * puts a rider in the wrong place.
 */
class CourierZoneTest extends TestCase
{
    use RefreshDatabase;

    private Courier $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->courier = Courier::where('slug', 'pathao')->first();
        $this->courier->update(['credentials' => [
            'default_city_id' => '1',
            'default_zone_id' => '100',
        ]]);
        $this->courier->refresh();
    }

    private function orderTo(string $city, ?string $zone = null, array $extra = []): Order
    {
        return Order::create([
            'order_number' => 'ORD-Z'.rand(1000, 9999),
            'session_id' => str_repeat('z', 40),
            'status' => 'pending', 'subtotal' => 1000, 'shipping_fee' => 0,
            'discount' => 0, 'total' => 1000,
            'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => array_merge([
                'name' => 'Rahim', 'phone' => '01712345678',
                'street_address' => 'House 45', 'city' => $city, 'zone' => $zone,
            ], $extra),
        ]);
    }

    private function map(string $city, ?string $zone, array $ids): CourierZone
    {
        return CourierZone::create(array_merge(
            ['courier_id' => $this->courier->id, 'city' => $city, 'zone' => $zone],
            $ids
        ));
    }

    // --- the fallback that used to be the only behaviour ------------------

    public function test_an_unmapped_address_still_uses_the_default(): void
    {
        $ids = ZoneResolver::for($this->orderTo('Rajshahi'), $this->courier);

        $this->assertSame('1', $ids['city_id']);
        $this->assertSame('100', $ids['zone_id']);
    }

    // --- what the mapping is for ------------------------------------------

    public function test_a_mapped_city_beats_the_default(): void
    {
        $this->map('Rajshahi', null, ['city_id' => '9', 'zone_id' => '901']);

        $ids = ZoneResolver::for($this->orderTo('Rajshahi'), $this->courier);

        $this->assertSame('9', $ids['city_id']);
        $this->assertSame('901', $ids['zone_id']);
    }

    /**
     * A shop maps Dhaka once, then refines Dhanmondi. The finer row has to win,
     * or the refinement does nothing.
     */
    public function test_a_mapped_zone_beats_a_mapped_city(): void
    {
        $this->map('Dhaka', null, ['city_id' => '1', 'zone_id' => '111']);
        $this->map('Dhaka', 'Dhanmondi', ['city_id' => '1', 'zone_id' => '222']);

        $this->assertSame('222', ZoneResolver::for($this->orderTo('Dhaka', 'Dhanmondi'), $this->courier)['zone_id']);
        // And an unnamed part of Dhaka still gets the city-wide row.
        $this->assertSame('111', ZoneResolver::for($this->orderTo('Dhaka', 'Uttara'), $this->courier)['zone_id']);
    }

    /**
     * Somebody who looked the address up on the courier's own panel knows
     * better than any table here.
     */
    public function test_an_id_put_on_the_order_by_hand_beats_everything(): void
    {
        $this->map('Dhaka', null, ['city_id' => '1', 'zone_id' => '111']);

        $order = $this->orderTo('Dhaka', null, ['pathao_zone_id' => '777']);

        $this->assertSame('777', ZoneResolver::for($order, $this->courier)['zone_id']);
    }

    // --- what people actually type ----------------------------------------

    /**
     * Checkout takes free text. The same district arrives capitalised three
     * ways and padded with spaces, and every one of them is the same place.
     */
    public function test_the_match_ignores_case_and_stray_spacing(): void
    {
        $this->map('Cox\'s  Bazar', null, ['city_id' => '5', 'zone_id' => '500']);

        foreach (["cox's bazar", "COX'S BAZAR", "  Cox's   Bazar  "] as $typed) {
            $this->assertSame(
                '500',
                ZoneResolver::for($this->orderTo($typed), $this->courier)['zone_id'],
                "\"{$typed}\" should have matched the mapping."
            );
        }
    }

    public function test_mappings_do_not_leak_between_couriers(): void
    {
        $redx = Courier::where('slug', 'redx')->first();

        $this->map('Sylhet', null, ['city_id' => '3', 'zone_id' => '300']);

        // Pathao's Sylhet row must not answer for RedX.
        $this->assertNull(ZoneResolver::for($this->orderTo('Sylhet'), $redx)['area_id']);
        $this->assertSame('3', ZoneResolver::for($this->orderTo('Sylhet'), $this->courier)['city_id']);
    }

    /** RedX books on an area rather than a city and zone. */
    public function test_redx_reads_the_area_from_the_same_mapping(): void
    {
        $redx = Courier::where('slug', 'redx')->first();

        CourierZone::create([
            'courier_id' => $redx->id, 'city' => 'Khulna', 'zone' => null,
            'area_id' => '4210',
        ]);

        $this->assertSame('4210', ZoneResolver::for($this->orderTo('Khulna'), $redx)['area_id']);
    }

    /**
     * A mapping row carries an empty string for the ids its courier does not
     * use, and an empty string is not an answer — it must fall through.
     */
    public function test_a_blank_id_in_a_mapping_falls_through_to_the_default(): void
    {
        $this->map('Barishal', null, ['city_id' => '', 'zone_id' => '']);

        $ids = ZoneResolver::for($this->orderTo('Barishal'), $this->courier);

        $this->assertSame('1', $ids['city_id']);
        $this->assertSame('100', $ids['zone_id']);
    }

    public function test_an_address_with_no_city_at_all_falls_back(): void
    {
        $this->map('Dhaka', null, ['city_id' => '1', 'zone_id' => '111']);

        $this->assertSame('100', ZoneResolver::for($this->orderTo(''), $this->courier)['zone_id']);
    }

    // --- managing them -----------------------------------------------------

    public function test_an_owner_can_map_and_unmap_an_area(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);

        $this->actingAs($owner)
            ->postJson("/api/admin/couriers/{$this->courier->id}/zones", [
                'city' => 'Chattogram', 'zone' => 'Agrabad',
                'city_id' => '2', 'zone_id' => '250',
            ])->assertOk();

        $zone = CourierZone::firstWhere('city', 'chattogram');
        $this->assertSame('agrabad', $zone->zone);
        $this->assertSame('250', $zone->zone_id);

        $this->actingAs($owner)
            ->deleteJson("/api/admin/couriers/{$this->courier->id}/zones/{$zone->id}")
            ->assertOk();

        $this->assertSame(0, CourierZone::count());
    }

    /** Correcting a mapping means typing the same place again. */
    public function test_mapping_the_same_place_twice_updates_rather_than_duplicates(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);

        foreach (['250', '260'] as $id) {
            $this->actingAs($owner)
                ->postJson("/api/admin/couriers/{$this->courier->id}/zones", [
                    'city' => 'Chattogram', 'zone' => 'Agrabad', 'zone_id' => $id,
                ])->assertOk();
        }

        $this->assertSame(1, CourierZone::count());
        $this->assertSame('260', CourierZone::first()->zone_id);
    }

    public function test_a_mapping_with_no_ids_at_all_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson("/api/admin/couriers/{$this->courier->id}/zones", ['city' => 'Bogura'])
            ->assertStatus(422);

        $this->assertSame(0, CourierZone::count());
    }
}
