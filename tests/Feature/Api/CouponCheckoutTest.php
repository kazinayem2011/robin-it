<?php

namespace Tests\Feature\Api;

use App\Constants\ApiEndpoints;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Coupons were validated at /coupons/apply but never reached the order: the
 * customer saw a discount and was then charged the full amount, and used_count
 * never moved so usage limits never applied.
 */
class CouponCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
    }

    private function product(int $price = 10000, int $stock = 10): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test CPU',
            'slug' => 'test-cpu-'.uniqid(),
            'price' => $price,
            'stock_quantity' => $stock,
            'is_active' => true,
        ]);
    }

    private function cartWith(User $user, Product $product, int $qty = 1): void
    {
        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id,
            'quantity' => $qty,
        ])->assertStatus(200);
    }

    private function address(array $extra = []): array
    {
        return array_merge([
            'name' => 'Rahim Chowdhury',
            'phone' => '01712345678',
            'street_address' => 'House 45, Road 7',
            'city' => 'Dhaka',
        ], $extra);
    }

    public function test_a_percentage_discount_is_applied_to_the_order_total(): void
    {
        $user = User::factory()->create();
        $product = $this->product(10000);
        $this->cartWith($user, $product);

        Coupon::create([
            'code' => 'SAVE10',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson(
            '/api/'.ApiEndpoints::CHECKOUT,
            $this->address(['coupon_code' => 'SAVE10'])
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.subtotal', 10000)
            ->assertJsonPath('data.discount', 1000)
            ->assertJsonPath('data.shipping_fee', 60)
            // 10000 - 1000 + 60
            ->assertJsonPath('data.total', 9060)
            ->assertJsonPath('data.coupon_code', 'SAVE10');
    }

    public function test_the_coupon_code_is_recorded_on_the_order(): void
    {
        $user = User::factory()->create();
        $this->cartWith($user, $this->product(5000));

        Coupon::create([
            'code' => 'FLAT500',
            'discount_type' => 'fixed',
            'discount_value' => 500,
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson(
            '/api/'.ApiEndpoints::CHECKOUT,
            $this->address(['coupon_code' => 'FLAT500'])
        )->assertStatus(201);

        $order = Order::first();
        $this->assertSame('FLAT500', $order->coupon_code);
        $this->assertEqualsWithDelta(500.0, $order->discount, 0.01);
        $this->assertEqualsWithDelta(4560.0, $order->total, 0.01);
    }

    public function test_redeeming_a_coupon_increments_its_usage_count(): void
    {
        $user = User::factory()->create();
        $this->cartWith($user, $this->product(5000));

        $coupon = Coupon::create([
            'code' => 'ONCE',
            'discount_type' => 'fixed',
            'discount_value' => 100,
            'usage_limit' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson(
            '/api/'.ApiEndpoints::CHECKOUT,
            $this->address(['coupon_code' => 'ONCE'])
        )->assertStatus(201);

        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_a_coupon_past_its_usage_limit_is_refused(): void
    {
        $user = User::factory()->create();
        $this->cartWith($user, $this->product(5000));

        Coupon::create([
            'code' => 'SPENT',
            'discount_type' => 'fixed',
            'discount_value' => 100,
            'usage_limit' => 1,
            'used_count' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson(
            '/api/'.ApiEndpoints::CHECKOUT,
            $this->address(['coupon_code' => 'SPENT'])
        )->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_expired_coupon_is_refused(): void
    {
        $user = User::factory()->create();
        $this->cartWith($user, $this->product(5000));

        Coupon::create([
            'code' => 'OLD',
            'discount_type' => 'percent',
            'discount_value' => 50,
            'expires_at' => now()->subDay(),
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson(
            '/api/'.ApiEndpoints::CHECKOUT,
            $this->address(['coupon_code' => 'OLD'])
        )->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
    }

    public function test_a_minimum_spend_cannot_be_faked_by_posting_a_subtotal(): void
    {
        $user = User::factory()->create();
        // A single ৳1,000 item — well under the ৳50,000 minimum spend.
        $this->cartWith($user, $this->product(1000));

        Coupon::create([
            'code' => 'BIGSPEND',
            'discount_type' => 'fixed',
            'discount_value' => 900,
            'min_spend' => 50000,
            'is_active' => true,
        ]);

        // The client claims a large subtotal; the server prices the real cart.
        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::COUPONS_APPLY, [
            'code' => 'BIGSPEND',
            'subtotal' => 999999,
        ])->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');

        $this->actingAs($user)->postJson(
            '/api/'.ApiEndpoints::CHECKOUT,
            $this->address(['coupon_code' => 'BIGSPEND'])
        )->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
    }

    public function test_a_percentage_discount_respects_its_cap(): void
    {
        $user = User::factory()->create();
        $this->cartWith($user, $this->product(100000));

        Coupon::create([
            'code' => 'CAPPED',
            'discount_type' => 'percent',
            'discount_value' => 50,
            'max_discount' => 2000,
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson(
            '/api/'.ApiEndpoints::CHECKOUT,
            $this->address(['coupon_code' => 'CAPPED'])
        )->assertStatus(201)->assertJsonPath('data.discount', 2000);
    }

    public function test_an_unknown_code_is_refused_without_creating_an_order(): void
    {
        $user = User::factory()->create();
        $this->cartWith($user, $this->product(5000));

        $this->actingAs($user)->postJson(
            '/api/'.ApiEndpoints::CHECKOUT,
            $this->address(['coupon_code' => 'NOPE'])
        )->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_coupon_codes_are_case_insensitive(): void
    {
        $user = User::factory()->create();
        $this->cartWith($user, $this->product(5000));

        Coupon::create([
            'code' => 'MiXeD',
            'discount_type' => 'fixed',
            'discount_value' => 250,
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::COUPONS_APPLY, [
            'code' => '  mixed  ',
        ])->assertStatus(200)->assertJsonPath('data.discount', 250);
    }

    public function test_apply_returns_refreshed_totals_for_the_summary(): void
    {
        $user = User::factory()->create();
        $this->cartWith($user, $this->product(10000));

        Coupon::create([
            'code' => 'TENOFF',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::COUPONS_APPLY, [
            'code' => 'TENOFF',
        ])->assertStatus(200)
            ->assertJsonPath('data.totals.subtotal', 10000)
            ->assertJsonPath('data.totals.discount', 1000)
            ->assertJsonPath('data.totals.total', 9060);
    }
}
