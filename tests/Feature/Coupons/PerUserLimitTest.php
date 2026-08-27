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

    private function checkout(User $user, string $code): TestResponse
    {
        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product->id,
            'quantity' => 1,
        ])->assertStatus(200);

        return $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim',
            'phone' => '01712345678',
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

        $this->checkout(User::factory()->create(), $coupon->code)->assertStatus(201);
        $this->checkout(User::factory()->create(), $coupon->code)->assertStatus(201);

        $this->assertSame(2, Order::where('coupon_code', 'ONEPERPERSON')->count());
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
