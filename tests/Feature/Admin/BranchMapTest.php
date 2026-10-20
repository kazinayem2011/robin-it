<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Each branch's pin on the Showrooms map: dragged in the branch form, or
 * dropped from a pasted Google Maps link.
 */
class BranchMapTest extends TestCase
{
    use RefreshDatabase;

    private const PLACE = 'https://www.google.com/maps/place/Multiplan+Center/@23.7385,90.3830,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d23.7386123!4d90.3832456!16s';

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function branch(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Head Office', 'branch_type' => 'Showroom and Special Service Centre', 'city' => 'Dhaka',
            'address' => '50 Ruhama Tower, Purana Paltan', 'phone' => '01730752475', 'opening_hours' => '10 AM – 9 PM',
            'is_active' => true,
        ], $overrides);
    }

    private function locate(string $link)
    {
        return $this->actingAs($this->admin())->postJson('/api/admin/stores/locate', ['link' => $link]);
    }

    public function test_a_place_link_drops_the_pin_on_the_place_itself(): void
    {
        // The place's own pin (!3d/!4d), not where the map was centred (@).
        $this->locate(self::PLACE)->assertOk()
            ->assertJsonPath('data.latitude', 23.7386123)
            ->assertJsonPath('data.longitude', 90.3832456);
    }

    public function test_a_short_share_link_is_followed_to_the_place(): void
    {
        Http::fake(['maps.app.goo.gl/*' => Http::response('', 302, ['Location' => self::PLACE])]);

        $this->locate('https://maps.app.goo.gl/AbCdEf123')->assertOk()
            ->assertJsonPath('data.latitude', 23.7386123);
    }

    public function test_a_centred_map_and_embed_code_also_place(): void
    {
        $this->locate('https://www.google.com/maps/@23.7,90.4,15z')->assertOk()
            ->assertJsonPath('data.longitude', 90.4);

        $this->locate('<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3651.9!2d90.4117!3d23.7339!2m3"></iframe>')
            ->assertOk()->assertJsonPath('data.latitude', 23.7339)->assertJsonPath('data.longitude', 90.4117);
    }

    public function test_a_link_without_a_position_says_what_to_do(): void
    {
        $this->locate('https://www.google.com/maps/search/?api=1&query=Robins+Computer')
            ->assertStatus(422)
            ->assertJsonPath('message', 'That link does not say where the place is. In Google Maps, open the branch itself, then Share → Copy link — or drag the pin onto it.');

        $this->locate('https://example.com/where-we-are')->assertStatus(422)
            ->assertJsonPath('message', 'Paste the link from Google Maps: find the branch, then Share → Copy link.');
    }

    public function test_the_branch_saves_its_pin_and_can_clear_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/stores', $this->branch(['latitude' => 23.7339, 'longitude' => 90.4117]))
            ->assertStatus(201)
            ->assertJsonPath('data.latitude', 23.7339);

        $store = Store::firstOrFail();
        $this->actingAs($admin)->putJson("/api/admin/stores/{$store->id}", $this->branch(['latitude' => null, 'longitude' => null]))->assertOk();
        $this->assertNull($store->fresh()->latitude);
    }

    public function test_half_a_pin_is_refused(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/stores', $this->branch(['latitude' => 23.7]))
            ->assertStatus(422);

        $this->assertSame(0, Store::count());
    }

    public function test_the_showrooms_list_carries_the_pins(): void
    {
        Store::create($this->branch(['latitude' => 23.7339, 'longitude' => 90.4117]));

        $this->getJson('/api/stores')->assertOk()
            ->assertJsonPath('data.0.latitude', 23.7339)
            ->assertJsonPath('data.0.longitude', 90.4117);
    }
}
