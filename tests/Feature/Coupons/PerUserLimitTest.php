<?php

namespace Tests\Feature\Coupons;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A coupon's shop-wide `usage_limit` has always been enforced atomically, by a
 * conditional UPDATE that only one of two simultaneous checkouts can match.
 *
 * `per_user_limit` was not: it was counted in the controller, before the
 * transaction opened, so two checkouts fired together by one customer both read
 * "not used yet" and both went through. It is now re-counted inside redeem(),
 * behind a row lock, which is the same place the shop-wide limit is settled.
 */
class PerUserLimitTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Ryzen 5 7600',
            'slug' => 'ryzen-5-7600',
            'price' => 20000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        app(StockService::class)->receive([], [[
            'product_id' => $this->product->id,
            'quantity' => 50,
        ]]);
    }

    private function coupon(int $perUserLimit = 1): Coupon
    {
        return Coupon::create([
            'code' => 'ONEPERPERSON',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'per_user_limit' => $perUserLimit,
            'is_active' => true,
        ]);
    }

    private function checkout(User $user, string $code, string $phone = '01712345678'): TestResponse
    {
        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product->id,
            'quantity' => 1,
        ])->assertStatus(200);

        return $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim',
            'phone' => $phone,
            'street_address' => 'House 45',
            'city' => 'Dhaka',
            'coupon_code' => $code,
        ]);
    }

    public function test_a_customer_can_use_a_once_per_person_coupon_once(): void
    {
        $coupon = $this->coupon();
        $user = User::factory()->create();

        $this->checkout($user, $coupon->code)->assertStatus(201);

        $this->assertSame(1, Order::where('coupon_code', 'ONEPERPERSON')->count());
    }

    public function test_a_second_use_by_the_same_customer_is_refused(): void
    {
        $coupon = $this->coupon();
        $user = User::factory()->create();

        $this->checkout($user, $coupon->code)->assertStatus(201);
        $this->checkout($user, $coupon->code)->assertStatus(422);

        $this->assertSame(1, Order::where('coupon_code', 'ONEPERPERSON')->count());
    }

    public function test_a_different_customer_is_unaffected(): void
    {
        $coupon = $this->coupon();

        // Different people: different accounts and different numbers.
        $this->checkout(User::factory()->create(), $coupon->code, '01712345678')->assertStatus(201);
        $this->checkout(User::factory()->create(), $coupon->code, '01812345678')->assertStatus(201);

        $this->assertSame(2, Order::where('coupon_code', 'ONEPERPERSON')->count());
    }

    /** A guest checking out with no account at all. */
    private function guestCheckout(string $code, string $phone, ?string $email = null): TestResponse
    {
        // A fresh visitor each time: no account, a new session and cart.
        $this->app['auth']->forgetGuards();
        app('session.store')->flush();
        app('session.store')->setId(Str::random(40));
        $this->postJson('/api/cart', ['product_id' => $this->product->id, 'quantity' => 1])->assertStatus(200);
        $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());

        return $this->postJson('/api/checkout', [
            'name' => 'Guest', 'phone' => $phone, 'street_address' => 'House 9', 'city' => 'Dhaka',
            'coupon_code' => $code, 'email' => $email,
        ]);
    }

    /* A new number with the same email is still the same person. */
    public function test_a_guest_changing_number_but_not_email_is_refused(): void
    {
        $coupon = $this->coupon();
        $this->guestCheckout($coupon->code, '01799000444', 'rahim@example.com')->assertStatus(201);

        $this->guestCheckout($coupon->code, '01899000444', ' Rahim@Example.com ')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This email address has already used this coupon.');
    }

    public function test_a_different_number_and_email_is_a_different_customer(): void
    {
        $coupon = $this->coupon();
        $this->guestCheckout($coupon->code, '01799000444', 'rahim@example.com')->assertStatus(201);
        $this->guestCheckout($coupon->code, '01899000444', 'karim@example.com')->assertStatus(201);
    }

    /*
     * "Once per customer" meant nothing to a guest: with no account, the same
     * number could use the code on every order. The number is the customer.
     */
    public function test_a_guest_is_held_to_the_limit_by_their_number(): void
    {
        $coupon = $this->coupon();

        $this->guestCheckout($coupon->code, '01799000444')->assertStatus(201);
        $this->guestCheckout($coupon->code, '+880 1799-000444')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This mobile number has already used this coupon.');

        $this->guestCheckout($coupon->code, '01899000444')->assertStatus(201);
        $this->assertSame(2, Order::where('coupon_code', 'ONEPERPERSON')->count());
    }

    public function test_a_number_that_used_it_as_a_guest_is_refused_when_signed_in(): void
    {
        $coupon = $this->coupon();
        $this->guestCheckout($coupon->code, '01799000444')->assertStatus(201);

        $this->checkout(User::factory()->create(), $coupon->code, '01799000444')->assertStatus(422);
    }

    public function test_a_cancelled_order_does_not_use_it_up_for_a_guest(): void
    {
        $coupon = $this->coupon();
        $this->guestCheckout($coupon->code, '01799000444')->assertStatus(201);
        Order::latest('id')->first()->forceFill(['status' => 'cancelled'])->save();

        $this->guestCheckout($coupon->code, '01799000444')->assertStatus(201);
    }

    /**
     * The point of the change: redeem() itself refuses, not just the controller
     * check that runs before the transaction. This calls it directly, the way a
     * second concurrent request would arrive after the first had committed.
     */
    public function test_redeem_itself_enforces_the_per_user_cap(): void
    {
        $coupon = $this->coupon();
        $user = User::factory()->create();

        $this->checkout($user, $coupon->code)->assertStatus(201);

        $granted = DB::transaction(fn () => $coupon->fresh()->redeem($user->id));

        $this->assertFalse($granted, 'redeem() granted a second use past the per-user cap.');
    }

    /** Without a customer there is no per-user cap to apply. */
    public function test_redeem_still_honours_the_shop_wide_limit(): void
    {
        $coupon = Coupon::create([
            'code' => 'ONLYONE',
            'discount_type' => 'fixed',
            'discount_value' => 100,
            'usage_limit' => 1,
            'is_active' => true,
        ]);

        $this->assertTrue(DB::transaction(fn () => $coupon->fresh()->redeem()));
        $this->assertFalse(DB::transaction(fn () => $coupon->fresh()->redeem()));
    }
}
