<?php

namespace Tests\Feature\Customer;

use App\Constants\ApiEndpoints;
use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * saveAddress wrote division / district / address / is_default, none of which
 * existed on the addresses table, so every save returned a 500 and stored nothing.
 */
class AddressBookTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            // Who the parcel is for and the number the courier rings. Both are
            // columns the checkout picker has always read and nothing ever
            // wrote, because the form had no box for either.
            'name' => 'Rahim Uddin',
            'phone' => '01711223344',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
            'city' => 'Gulshan',
            'address' => 'House 45, Road 7, Gulshan 2',
            // What delivery costs to here. Required since the address became a
            // line the customer writes: neither the district, which is typed by
            // hand, nor the division, which reaches Gazipur, settles it.
            'delivery_zone' => 'inside_dhaka',
            'is_default' => true,
        ], $overrides);
    }

    public function test_a_customer_can_save_a_delivery_address(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/'.ApiEndpoints::ACCOUNT_ADDRESS, $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'division' => 'Dhaka',
            'district' => 'Dhaka',
            'city' => 'Gulshan',
            'address' => 'House 45, Road 7, Gulshan 2',
            'delivery_zone' => 'inside_dhaka',
            'is_default' => true,
        ]);
    }

    public function test_a_saved_address_keeps_who_it_is_for(): void
    {
        $user = User::factory()->create(['name' => 'Account Holder']);

        $this->actingAs($user)->post('/'.ApiEndpoints::ACCOUNT_ADDRESS, $this->payload([
            'name' => 'Karim at the office',
            'phone' => '01911223344',
        ]));

        // Not the account holder: an address may be someone else's door.
        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'name' => 'Karim at the office',
            'phone' => '01911223344',
        ]);
    }

    public function test_an_address_needs_a_recipient_and_a_number(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/'.ApiEndpoints::ACCOUNT_ADDRESS, array_diff_key(
                $this->payload(),
                array_flip(['name', 'phone'])
            ))
            ->assertSessionHasErrors(['name', 'phone']);

        $this->assertDatabaseCount('addresses', 0);
    }

    public function test_a_mobile_number_has_to_look_like_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/'.ApiEndpoints::ACCOUNT_ADDRESS, $this->payload(['phone' => '12345']))
            ->assertSessionHasErrors('phone');
    }

    public function test_the_first_address_becomes_the_default_automatically(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(
            '/'.ApiEndpoints::ACCOUNT_ADDRESS,
            $this->payload(['is_default' => false])
        );

        $this->assertTrue(Address::where('user_id', $user->id)->first()->is_default);
    }

    public function test_marking_an_address_default_clears_the_previous_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/'.ApiEndpoints::ACCOUNT_ADDRESS, $this->payload());
        $this->actingAs($user)->post('/'.ApiEndpoints::ACCOUNT_ADDRESS, $this->payload([
            'city' => 'Banani',
            'address' => 'House 12, Road 11, Banani',
        ]));

        $defaults = Address::where('user_id', $user->id)->where('is_default', true)->get();

        $this->assertCount(1, $defaults, 'Exactly one address may be the default.');
        $this->assertSame('Banani', $defaults->first()->city);
    }

    public function test_a_customer_can_update_their_own_address(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/'.ApiEndpoints::ACCOUNT_ADDRESS, $this->payload());

        $address = Address::where('user_id', $user->id)->first();

        $this->actingAs($user)->post('/'.ApiEndpoints::ACCOUNT_ADDRESS, $this->payload([
            'id' => $address->id,
            'address' => 'Flat 5B, House 45, Road 7',
        ]));

        $this->assertSame('Flat 5B, House 45, Road 7', $address->fresh()->address);
        $this->assertDatabaseCount('addresses', 1);
    }

    public function test_a_customer_cannot_edit_someone_elses_address(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $this->actingAs($owner)->post('/'.ApiEndpoints::ACCOUNT_ADDRESS, $this->payload());
        $victimAddress = Address::where('user_id', $owner->id)->first();

        $this->actingAs($attacker)->post('/'.ApiEndpoints::ACCOUNT_ADDRESS, $this->payload([
            'id' => $victimAddress->id,
            'address' => 'Hijacked address',
        ]))->assertNotFound();

        $this->assertSame('House 45, Road 7, Gulshan 2', $victimAddress->fresh()->address);
    }

    public function test_deleting_the_default_promotes_another_address(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/'.ApiEndpoints::ACCOUNT_ADDRESS, $this->payload([
            'is_default' => false,
            'city' => 'Banani',
        ]));
        $this->actingAs($user)->post('/'.ApiEndpoints::ACCOUNT_ADDRESS, $this->payload([
            'city' => 'Gulshan',
        ]));

        $default = Address::where('user_id', $user->id)->where('is_default', true)->first();

        $this->actingAs($user)
            ->delete('/'.str_replace('{id}', $default->id, ApiEndpoints::ACCOUNT_ADDRESS_ITEM));

        $this->assertDatabaseCount('addresses', 1);
        $this->assertTrue(
            Address::where('user_id', $user->id)->first()->is_default,
            'The remaining address should become the default.'
        );
    }

    public function test_a_missing_street_address_is_rejected_with_a_helpful_message(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/'.ApiEndpoints::ACCOUNT_ADDRESS, $this->payload(['address' => '']))
            ->assertSessionHasErrors('address');

        $this->assertDatabaseCount('addresses', 0);
    }
}
