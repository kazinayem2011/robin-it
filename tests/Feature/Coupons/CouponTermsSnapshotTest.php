<?php

namespace Tests\Feature\Coupons;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orders recorded the promo code and the money, and nothing about the terms.
 *
 * The amount was always right, so no figure was ever wrong. What was missing
 * is *why*: edit SAVE10 from 10% to 90%, or delete it, and the order still
 * read "SAVE10, ৳100 off" with no way to check that ৳100 was correct — which
 * is exactly the question a dispute, a refund or an audit asks.
 */
class CouponTermsSnapshotTest extends TestCase
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
            'product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 600,
        ]]);
    }

    private function buyWith(?string $code): Order
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product->id, 'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', array_filter([
            'name' => 'Rahim', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
            'coupon_code' => $code,
        ]))->assertStatus(201);

        return Order::latest('id')->first();
    }

    public function test_a_percentage_coupons_terms_are_frozen(): void
    {
        Coupon::create(['code' => 'SAVE10', 'discount_type' => 'percent',
            'discount_value' => 10, 'is_active' => true]);

        $order = $this->buyWith('SAVE10');

        $this->assertSame('SAVE10', $order->coupon_code);
        $this->assertSame('percent', $order->coupon_discount_type);
        $this->assertSame(10.0, $order->coupon_discount_value);
        $this->assertSame(100.0, (float) $order->discount);
        $this->assertSame('10% off', $order->coupon_terms);
    }

    public function test_a_fixed_coupons_terms_are_frozen(): void
    {
        Coupon::create(['code' => 'TAKE250', 'discount_type' => 'fixed',
            'discount_value' => 250, 'is_active' => true]);

        $order = $this->buyWith('TAKE250');

        $this->assertSame('fixed', $order->coupon_discount_type);
        $this->assertSame(250.0, $order->coupon_discount_value);
        $this->assertSame('৳250 off', $order->coupon_terms);
    }

    /** The whole point: the order stays explainable after the coupon changes. */
    public function test_rewriting_the_coupon_does_not_change_the_order(): void
    {
        Coupon::create(['code' => 'SAVE10', 'discount_type' => 'percent',
            'discount_value' => 10, 'is_active' => true]);

        $order = $this->buyWith('SAVE10');

        Coupon::where('code', 'SAVE10')->update([
            'discount_type' => 'percent', 'discount_value' => 90,
        ]);

        $order = $order->fresh();

        $this->assertSame(10.0, $order->coupon_discount_value, 'The order picked up the new terms.');
        $this->assertSame('10% off', $order->coupon_terms);
        $this->assertSame(100.0, (float) $order->discount);
    }

    public function test_deleting_the_coupon_leaves_the_order_explainable(): void
    {
        Coupon::create(['code' => 'SAVE10', 'discount_type' => 'percent',
            'discount_value' => 10, 'is_active' => true]);

        $order = $this->buyWith('SAVE10');

        Coupon::where('code', 'SAVE10')->delete();

        $order = $order->fresh();

        $this->assertFalse(Coupon::where('code', 'SAVE10')->exists());
        $this->assertSame('SAVE10', $order->coupon_code);
        $this->assertSame('10% off', $order->coupon_terms, 'The terms went with the coupon.');
    }

    public function test_an_order_without_a_coupon_has_no_terms(): void
    {
        $order = $this->buyWith(null);

        $this->assertNull($order->coupon_code);
        $this->assertNull($order->coupon_discount_type);
        $this->assertNull($order->coupon_terms);
        $this->assertSame(0.0, (float) $order->discount);
    }
}
