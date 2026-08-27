<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentAndCouponPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
    }

    private function product(int $price = 5000): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test CPU',
            'slug' => 'test-cpu-'.uniqid(),
            'price' => $price,
            'stock_quantity' => 50,
            'is_active' => true,
        ]);
    }

    private function checkout(User $user, array $extra = [])
    {
        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product()->id, 'quantity' => 1,
        ])->assertStatus(200);

        return $this->actingAs($user)->postJson('/api/checkout', array_merge([
            'name' => 'Rahim Chowdhury',
            'phone' => '01712345678',
            'street_address' => 'House 45, Road 7',
            'city' => 'Dhaka',
        ], $extra));
    }

    // ---------------------------------------------------------- payment policy

    /**
     * The API accepted BKASH and NAGAD while nothing processed an online payment,
     * so a crafted request could record an order as paid by bKash that nobody paid.
     */
    public static function unsupportedMethodProvider(): array
    {
        return [
            'bkash' => ['BKASH'],
            'nagad' => ['NAGAD'],
            'card' => ['CARD'],
            'rocket' => ['ROCKET'],
        ];
    }

    #[DataProvider('unsupportedMethodProvider')]
    public function test_unsupported_payment_methods_are_refused(string $method): void
    {
        $user = User::factory()->create();

        $response = $this->checkout($user, ['payment_method' => $method]);

        $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->assertStringContainsString('Cash on Delivery', $response->json('message'));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cash_on_delivery_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->checkout($user, ['payment_method' => 'COD'])->assertStatus(201);

        $this->assertDatabaseHas('orders', ['payment_method' => 'COD', 'payment_status' => 'unpaid']);
    }

    public function test_the_storefronts_lowercase_cod_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->checkout($user, ['payment' => 'cod'])->assertStatus(201);

        $this->assertDatabaseHas('orders', ['payment_method' => 'COD']);
    }

    public function test_no_order_can_be_created_with_a_method_outside_the_allow_list(): void
    {
        $this->assertSame(['COD'], Order::PAYMENT_METHODS);

        $user = User::factory()->create();
        $this->checkout($user)->assertStatus(201);

        $this->assertSame(
            ['COD'],
            Order::pluck('payment_method')->unique()->values()->all()
        );
    }

    // ---------------------------------------------------------- coupon policy

    public function test_a_customer_cannot_reuse_a_single_use_coupon(): void
    {
        $user = User::factory()->create();

        Coupon::create([
            'code' => 'WELCOME10',
            'discount_type' => 'fixed',
            'discount_value' => 200,
            'per_user_limit' => 1,
            'is_active' => true,
        ]);

        $this->checkout($user, ['coupon_code' => 'WELCOME10'])->assertStatus(201);

        // Second order by the same customer with the same code.
        $second = $this->checkout($user, ['coupon_code' => 'WELCOME10']);

        $second->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
        $this->assertStringContainsString('already used', $second->json('message'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_a_different_customer_can_still_use_it(): void
    {
        Coupon::create([
            'code' => 'WELCOME10',
            'discount_type' => 'fixed',
            'discount_value' => 200,
            'per_user_limit' => 1,
            'is_active' => true,
        ]);

        $this->checkout(User::factory()->create(), ['coupon_code' => 'WELCOME10'])->assertStatus(201);
        $this->checkout(User::factory()->create(), ['coupon_code' => 'WELCOME10'])->assertStatus(201);

        $this->assertDatabaseCount('orders', 2);
    }

    public function test_a_multi_use_allowance_is_respected(): void
    {
        $user = User::factory()->create();

        Coupon::create([
            'code' => 'TWICE',
            'discount_type' => 'fixed',
            'discount_value' => 100,
            'per_user_limit' => 2,
            'is_active' => true,
        ]);

        $this->checkout($user, ['coupon_code' => 'TWICE'])->assertStatus(201);
        $this->checkout($user, ['coupon_code' => 'TWICE'])->assertStatus(201);
        $this->checkout($user, ['coupon_code' => 'TWICE'])->assertStatus(422);

        $this->assertDatabaseCount('orders', 2);
    }

    public function test_a_cancelled_order_frees_the_customers_allowance(): void
    {
        $user = User::factory()->create();

        Coupon::create([
            'code' => 'ONEUSE',
            'discount_type' => 'fixed',
            'discount_value' => 100,
            'per_user_limit' => 1,
            'is_active' => true,
        ]);

        $this->checkout($user, ['coupon_code' => 'ONEUSE'])->assertStatus(201);

        // Cancelling should not consume the customer's single allowance.
        Order::first()->update(['status' => 'cancelled']);

        $this->checkout($user, ['coupon_code' => 'ONEUSE'])->assertStatus(201);
    }

    public function test_an_unlimited_coupon_has_no_per_customer_cap(): void
    {
        $user = User::factory()->create();

        Coupon::create([
            'code' => 'ALWAYS',
            'discount_type' => 'fixed',
            'discount_value' => 50,
            'per_user_limit' => null,
            'is_active' => true,
        ]);

        $this->checkout($user, ['coupon_code' => 'ALWAYS'])->assertStatus(201);
        $this->checkout($user, ['coupon_code' => 'ALWAYS'])->assertStatus(201);
        $this->checkout($user, ['coupon_code' => 'ALWAYS'])->assertStatus(201);

        $this->assertDatabaseCount('orders', 3);
    }

    public function test_the_preview_endpoint_reports_the_per_user_limit_too(): void
    {
        $user = User::factory()->create();

        Coupon::create([
            'code' => 'ONCEONLY',
            'discount_type' => 'fixed',
            'discount_value' => 100,
            'per_user_limit' => 1,
            'is_active' => true,
        ]);

        $this->checkout($user, ['coupon_code' => 'ONCEONLY'])->assertStatus(201);

        // Fill the cart again so the coupon preview has a subtotal to work with.
        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $this->product()->id, 'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/coupons/apply', ['code' => 'ONCEONLY'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'COUPON_INVALID');
    }
}
