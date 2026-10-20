<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use App\Support\MapLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A branch's map, from the Google Maps link staff paste.
 */
class BranchMapTest extends TestCase
{
    use RefreshDatabase;

    private const PLACE = 'https://www.google.com/maps/place/Multiplan+Center/@23.7385,90.3830,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d23.7386123!4d90.3832456!16s';

    private function branch(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Polton Branch', 'branch_type' => 'Showroom and Special Service Centre', 'city' => 'Dhaka',
            'address' => '50 Ruhama mension, Purana Paltan', 'phone' => '01730752475', 'opening_hours' => '10 AM – 9 PM',
            'is_active' => true,
        ], $overrides);
    }

    public function test_a_place_link_pins_the_place_itself(): void
    {
        // The place's own pin (!3d/!4d), not where the map happened to be centred (@).
        $this->assertSame(
            'https://maps.google.com/maps?q=23.7386123%2C90.3832456&z=17&output=embed',
            MapLink::embedFor(self::PLACE)
        );
    }

    public function test_a_short_share_link_is_followed_to_the_place(): void
    {
        Http::fake(['maps.app.goo.gl/*' => Http::response('', 302, ['Location' => self::PLACE])]);

        $this->assertSame(
            'https://maps.google.com/maps?q=23.7386123%2C90.3832456&z=17&output=embed',
            MapLink::embedFor('https://maps.app.goo.gl/AbCdEf123')
        );
    }

    public function test_a_centred_map_or_a_search_still_places(): void
    {
        $this->assertStringContainsString('q=23.7%2C90.4', MapLink::embedFor('https://www.google.com/maps/@23.7,90.4,15z'));
        $this->assertStringContainsString('q=Robins%20Computer', MapLink::embedFor('https://www.google.com/maps/search/?api=1&query=Robins+Computer'));
    }

    public function test_embed_code_is_taken_as_it_is(): void
    {
        $src = 'https://www.google.com/maps/embed?pb=!1m18!1m12';

        $this->assertSame($src, MapLink::embedFor('<iframe src="'.$src.'" width="600" height="450"></iframe>'));
    }

    public function test_nothing_pasted_is_no_map(): void
    {
        $this->assertNull(MapLink::embedFor('  '));
    }

    public function test_the_admin_saves_the_link_and_the_map(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/stores', $this->branch(['map_link' => self::PLACE]))
            ->assertStatus(201)
            ->assertJsonPath('data.map_link', self::PLACE)
            ->assertJsonPath('data.map_embed_url', 'https://maps.google.com/maps?q=23.7386123%2C90.3832456&z=17&output=embed');

        // Cleared, the map goes with it.
        $store = Store::firstOrFail();
        $this->actingAs($admin)->putJson("/api/admin/stores/{$store->id}", $this->branch(['map_link' => '']))->assertOk();
        $this->assertNull($store->fresh()->map_link);
        $this->assertNull($store->fresh()->map_embed_url);
    }

    public function test_a_link_that_is_not_google_maps_is_refused_under_its_field(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/admin/stores', $this->branch(['map_link' => 'https://example.com/where-we-are']))
            ->assertStatus(422)
            ->assertJsonFragment(['Paste the link from Google Maps: find the branch, then Share → Copy link.']);

        $this->assertSame(0, Store::count());
    }

    public function test_the_showrooms_list_carries_the_map(): void
    {
        Store::create($this->branch(['map_link' => self::PLACE, 'map_embed_url' => 'https://maps.google.com/maps?q=1%2C2&z=17&output=embed']));

        $this->getJson('/api/stores')->assertOk()
            ->assertJsonPath('data.0.map_embed_url', 'https://maps.google.com/maps?q=1%2C2&z=17&output=embed');
    }
}
