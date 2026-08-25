<?php

namespace Tests\Feature\Customer;

use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The account area is a page per section.
 *
 * It used to be one screen switching tabs in local state, so none of the
 * sections had a URL — orders could not be linked to, bookmarked or opened in
 * a second tab, and every visit loaded all of them whichever one you wanted.
 */
class AccountPagesTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $user, string $status = 'pending'): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.uniqid(),
            'subtotal' => 1000,
            'shipping_fee' => 60,
            'discount' => 0,
            'total' => 1060,
            'status' => $status,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01712345678'],
        ]);
    }

    private function address(User $user): Address
    {
        return Address::create([
            'user_id' => $user->id,
            'name' => 'Rahim Chowdhury',
            'phone' => '01712345678',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
            'city' => 'Dhaka',
            'address' => 'House 45',
            'is_default' => true,
        ]);
    }

    public static function pageProvider(): array
    {
        return [
            'overview' => ['/dashboard', 'Dashboard/Index'],
            'orders' => ['/dashboard/orders', 'Dashboard/Orders'],
            'wishlist' => ['/dashboard/wishlist', 'Dashboard/Wishlist'],
            'addresses' => ['/dashboard/addresses', 'Dashboard/Addresses'],
            'profile' => ['/dashboard/profile', 'Dashboard/Profile'],
        ];
    }

    #[DataProvider('pageProvider')]
    public function test_each_section_has_its_own_page(string $url, string $component): void
    {
        $response = $this->actingAs(User::factory()->create())->get($url);

        $response->assertStatus(200);
        $this->assertSame($component, $response->viewData('page')['component']);
    }

    #[DataProvider('pageProvider')]
    public function test_every_section_is_behind_the_login(string $url): void
    {
        $this->get($url)->assertRedirect('/login');
    }

    /** The header and the sidebar counts appear on all of them. */
    #[DataProvider('pageProvider')]
    public function test_every_section_carries_what_the_frame_needs(string $url): void
    {
        $props = $this->actingAs(User::factory()->create())
            ->get($url)
            ->viewData('page')['props'];

        $this->assertArrayHasKey('user', $props);
        $this->assertArrayHasKey('navCounts', $props);
        $this->assertArrayHasKey('techPoints', $props);
    }

    /**
     * The point of splitting them. Asking for the address book used to load
     * every order with all its items and every wishlist product too.
     */
    public function test_a_section_does_not_load_another_section_s_data(): void
    {
        $user = User::factory()->create();
        $this->address($user);

        $props = $this->actingAs($user)->get('/dashboard/addresses')
            ->viewData('page')['props'];

        $this->assertArrayHasKey('addresses', $props);
        $this->assertArrayNotHasKey('orders', $props);
        $this->assertArrayNotHasKey('wishlistItems', $props);
    }

    public function test_the_overview_sends_only_a_few_recent_orders(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->order($user);
        }

        $props = $this->actingAs($user)->get('/dashboard')->viewData('page')['props'];

        $this->assertCount(3, $props['recentOrders']);
        $this->assertSame(6, $props['stats']['total_orders']);
    }

    public function test_one_customer_cannot_see_another_s_orders(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $this->order($theirs);

        $props = $this->actingAs($mine)->get('/dashboard/orders')
            ->viewData('page')['props'];

        $this->assertCount(0, $props['orders']);
    }
}
