<?php

namespace Tests\Feature\Checkout;

use App\Models\Address;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\AddressBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A returning customer should not retype where they live.
 *
 * The address book and the checkout form never met: a customer could have
 * three addresses saved and still be handed five empty boxes, and whatever
 * they typed was thrown away once the order was placed.
 */
class AddressBookTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_CUSTOMER,
            'name' => 'Rahim Chowdhury',
            'phone' => '01712345678',
        ], $attributes));
    }

    private function delivery(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahim Chowdhury',
            'phone' => '01712345678',
            'city' => 'Dhaka',
            'zone' => 'Dhanmondi',
            'street_address' => 'House 12, Road 4',
        ], $overrides);
    }

    private function product(): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'cpu'],
            ['name' => 'CPU', 'is_active' => true]
        );

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test CPU',
            'slug' => 'test-cpu-'.uniqid(),
            'price' => 5000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
    }

    private function addToCart(User $user): Product
    {
        $product = $this->product();

        $this->actingAs($user)
            ->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 1])
            ->assertSuccessful();

        return $product;
    }

    public function test_the_checkout_page_opens_with_a_saved_address(): void
    {
        $user = $this->customer();

        Address::create([
            'user_id' => $user->id,
            'name' => 'Rahim Chowdhury',
            'phone' => '01712345678',
            'city' => 'Dhaka',
            'zone' => 'Dhanmondi',
            'street_address' => 'House 12, Road 4',
            'address' => 'House 12, Road 4',
            'is_default' => true,
        ]);

        $props = $this->actingAs($user)
            ->get('/checkout')
            ->assertStatus(200)
            ->viewData('page')['props'];

        $this->assertCount(1, $props['addresses']);
        $this->assertSame('House 12, Road 4', $props['addresses'][0]['street_address']);
        $this->assertSame('Dhanmondi', $props['addresses'][0]['zone']);
        $this->assertTrue($props['addresses'][0]['is_default']);
    }

    /** The default one first, so the form opens with it. */
    public function test_the_default_address_leads_the_list(): void
    {
        $user = $this->customer();

        Address::create([
            'user_id' => $user->id, 'city' => 'Dhaka', 'zone' => 'Uttara',
            'street_address' => 'Office', 'address' => 'Office', 'is_default' => false,
        ]);
        Address::create([
            'user_id' => $user->id, 'city' => 'Dhaka', 'zone' => 'Dhanmondi',
            'street_address' => 'Home', 'address' => 'Home', 'is_default' => true,
        ]);

        $props = $this->actingAs($user)->get('/checkout')->viewData('page')['props'];

        $this->assertSame('Home', $props['addresses'][0]['street_address']);
    }

    /** A first-time buyer still should not retype what they registered with. */
    public function test_a_customer_with_no_addresses_still_gets_their_name_and_number(): void
    {
        $props = $this->actingAs($this->customer())
            ->get('/checkout')
            ->viewData('page')['props'];

        $this->assertSame([], $props['addresses']);
        $this->assertSame('Rahim Chowdhury', $props['contact']['name']);
        $this->assertSame('01712345678', $props['contact']['phone']);
    }

    public function test_a_guest_is_offered_nothing(): void
    {
        $props = $this->get('/checkout')->viewData('page')['props'];

        $this->assertSame([], $props['addresses']);
        $this->assertNull($props['contact']);
    }

    public function test_placing_an_order_keeps_the_address(): void
    {
        $user = $this->customer();
        $this->addToCart($user);

        $this->actingAs($user)
            ->postJson('/api/checkout', $this->delivery())
            ->assertSuccessful();

        $saved = Address::where('user_id', $user->id)->get();

        $this->assertCount(1, $saved);
        $this->assertSame('House 12, Road 4', $saved[0]->street_address);
        $this->assertSame('Dhanmondi', $saved[0]->zone);
        // Mirrored, because the address book renders `address`.
        $this->assertSame('House 12, Road 4', $saved[0]->address);
        // The first one is the one to open with.
        $this->assertTrue($saved[0]->is_default);
    }

    /** Otherwise a weekly order to the same house collects fifty-two copies. */
    public function test_ordering_twice_to_the_same_place_saves_one_address(): void
    {
        $user = $this->customer();

        foreach ([1, 2] as $_) {
            $this->addToCart($user);
            $this->actingAs($user)
                ->postJson('/api/checkout', $this->delivery())
                ->assertSuccessful();
        }

        $this->assertSame(1, Address::where('user_id', $user->id)->count());
    }

    public function test_the_same_address_typed_differently_is_still_the_same_address(): void
    {
        $user = $this->customer();

        $this->addToCart($user);
        $this->actingAs($user)->postJson('/api/checkout', $this->delivery())->assertSuccessful();

        $this->addToCart($user);
        $this->actingAs($user)->postJson('/api/checkout', $this->delivery([
            'street_address' => '  house 12,   ROAD 4 ',
            'city' => 'dhaka',
            'zone' => 'DHANMONDI',
        ]))->assertSuccessful();

        $this->assertSame(1, Address::where('user_id', $user->id)->count());
    }

    public function test_a_second_address_is_kept_alongside_the_first(): void
    {
        $user = $this->customer();

        $this->addToCart($user);
        $this->actingAs($user)->postJson('/api/checkout', $this->delivery())->assertSuccessful();

        $this->addToCart($user);
        $this->actingAs($user)->postJson('/api/checkout', $this->delivery([
            'street_address' => 'Flat 9, Sector 7',
            'zone' => 'Uttara',
        ]))->assertSuccessful();

        $addresses = Address::where('user_id', $user->id)->orderBy('id')->get();

        $this->assertCount(2, $addresses);
        // The first stays the default; a new delivery does not move house.
        $this->assertTrue($addresses[0]->is_default);
        $this->assertFalse($addresses[1]->is_default);
    }

    /**
     * A guest has nowhere to keep an address.
     *
     * Tested at the class that decides it rather than through a guest
     * checkout: a guest cart hangs off the session id, and the test client
     * starts a fresh session per request, so the checkout would find an empty
     * cart rather than exercise this at all.
     */
    public function test_a_guest_is_given_no_address_book(): void
    {
        $this->assertNull(AddressBook::remember(null, $this->delivery()));
        $this->assertSame(0, Address::count());

        $offered = AddressBook::forCheckout(null);
        $this->assertSame([], $offered['addresses']);
        $this->assertNull($offered['contact']);
    }

    public function test_an_address_is_not_kept_when_the_order_fails(): void
    {
        $user = $this->customer();
        $this->addToCart($user);

        // No such coupon, so the order is refused before it is placed.
        $this->actingAs($user)
            ->postJson('/api/checkout', $this->delivery(['coupon_code' => 'NOPE-NOT-REAL']))
            ->assertStatus(422);

        $this->assertSame(0, Address::where('user_id', $user->id)->count());
    }

    public function test_a_blank_address_is_never_stored(): void
    {
        $user = $this->customer();

        $this->assertNull(AddressBook::remember($user, ['street_address' => '  ', 'city' => 'Dhaka']));
        $this->assertNull(AddressBook::remember($user, ['street_address' => 'House 1', 'city' => '']));
        $this->assertSame(0, Address::count());
    }

    /** @depends test_placing_an_order_keeps_the_address */
    public function test_what_is_saved_comes_back_on_the_next_checkout(): void
    {
        $user = $this->customer();
        $this->addToCart($user);

        $this->actingAs($user)->postJson('/api/checkout', $this->delivery())->assertSuccessful();

        $props = $this->actingAs($user)->get('/checkout')->viewData('page')['props'];

        $this->assertCount(1, $props['addresses']);
        $this->assertSame('House 12, Road 4', $props['addresses'][0]['street_address']);
        $this->assertSame('Dhaka', $props['addresses'][0]['city']);
    }
}
