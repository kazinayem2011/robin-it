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
 * Coupons that target part of the catalogue.
 *
 * The rule being pinned: a scoped coupon may only discount the lines it covers.
 * Discounting the whole basket off a category promo is the expensive mistake.
 */
class ScopedCouponTest extends TestCase
{
    use RefreshDatabase;

    private Category $gpus;

    private Category $cpus;

    private Product $gpu;

    private Product $cpu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gpus = Category::create(['name' => 'Graphics Cards', 'slug' => 'gpu', 'is_active' => true]);
        $this->cpus = Category::create(['name' => 'Processors', 'slug' => 'cpu', 'is_active' => true]);

        $this->gpu = $this->product('RTX 4070 Super', $this->gpus, 10000);
        $this->cpu = $this->product('Ryzen 7 7800X3D', $this->cpus, 20000);
    }

    private function product(string $name, Category $category, float $price): Product
    {
        $product = Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'price' => $price,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 20]]);

        return $product->fresh();
    }

    private function coupon(array $attributes = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'SAVE10',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'min_spend' => 0,
            'is_active' => true,
        ], $attributes));
    }

    private function fill(User $user, array $lines): void
    {
        foreach ($lines as [$product, $qty]) {
            $this->actingAs($user)->postJson('/cart-api', [
                'product_id' => $product->id, 'quantity' => $qty,
            ])->assertStatus(200);
        }
    }

    private function checkout(User $user, string $code)
    {
        return $this->actingAs($user)->postJson('/checkout-api', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
            'coupon_code' => $code,
        ]);
    }

    public function test_an_unscoped_coupon_still_discounts_the_whole_cart(): void
    {
        $this->coupon();
        $user = User::factory()->create();
        $this->fill($user, [[$this->gpu, 1], [$this->cpu, 1]]);

        $this->checkout($user, 'SAVE10')->assertStatus(201);

        // 10% of the full 30,000.
        $this->assertEqualsWithDelta(3000.0, Order::latest()->first()->discount, 0.01);
    }

    /** The whole point: a category promo must not discount the rest of the basket. */
    public function test_a_category_coupon_only_discounts_that_category(): void
    {
        $coupon = $this->coupon(['scope' => Coupon::SCOPE_CATEGORIES]);
        $coupon->categories()->attach($this->gpus->id);

        $user = User::factory()->create();
        $this->fill($user, [[$this->gpu, 1], [$this->cpu, 1]]);

        $this->checkout($user, 'SAVE10')->assertStatus(201);

        // 10% of the 10,000 graphics card only — not of the 30,000 cart.
        $this->assertEqualsWithDelta(1000.0, Order::latest()->first()->discount, 0.01);
    }

    public function test_a_product_coupon_only_discounts_that_product(): void
    {
        $coupon = $this->coupon(['scope' => Coupon::SCOPE_PRODUCTS]);
        $coupon->products()->attach($this->cpu->id);

        $user = User::factory()->create();
        $this->fill($user, [[$this->gpu, 1], [$this->cpu, 1]]);

        $this->checkout($user, 'SAVE10')->assertStatus(201);

        $this->assertEqualsWithDelta(2000.0, Order::latest()->first()->discount, 0.01);
    }

    public function test_the_quantity_of_the_eligible_line_is_respected(): void
    {
        $coupon = $this->coupon(['scope' => Coupon::SCOPE_PRODUCTS]);
        $coupon->products()->attach($this->gpu->id);

        $user = User::factory()->create();
        $this->fill($user, [[$this->gpu, 3], [$this->cpu, 1]]);

        $this->checkout($user, 'SAVE10')->assertStatus(201);

        // 10% of 3 x 10,000.
        $this->assertEqualsWithDelta(3000.0, Order::latest()->first()->discount, 0.01);
    }

    public function test_a_coupon_covering_nothing_in_the_cart_is_refused(): void
    {
        $coupon = $this->coupon(['scope' => Coupon::SCOPE_CATEGORIES]);
        $coupon->categories()->attach($this->gpus->id);

        $user = User::factory()->create();
        $this->fill($user, [[$this->cpu, 1]]);

        $this->checkout($user, 'SAVE10')
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'This code does not apply to anything in your cart. It applies to Graphics Cards.',
            ]);

        $this->assertSame(0, Order::count());
    }

    /** A promo on a section should reach the products filed beneath it. */
    public function test_a_category_coupon_covers_child_categories(): void
    {
        $components = Category::create(['name' => 'Components', 'slug' => 'components', 'is_active' => true]);
        $this->gpus->update(['parent_id' => $components->id]);

        $coupon = $this->coupon(['scope' => Coupon::SCOPE_CATEGORIES]);
        $coupon->categories()->attach($components->id);

        $user = User::factory()->create();
        $this->fill($user, [[$this->gpu, 1]]);

        $this->checkout($user, 'SAVE10')->assertStatus(201);

        $this->assertEqualsWithDelta(1000.0, Order::latest()->first()->discount, 0.01);
    }

    public function test_a_fixed_discount_cannot_exceed_the_eligible_amount(): void
    {
        $coupon = $this->coupon([
            'code' => 'FLAT5000', 'discount_type' => 'fixed', 'discount_value' => 5000,
            'scope' => Coupon::SCOPE_PRODUCTS,
        ]);
        $coupon->products()->attach($this->gpu->id);

        $user = User::factory()->create();
        $this->fill($user, [[$this->gpu, 1], [$this->cpu, 1]]);

        $this->checkout($user, 'FLAT5000')->assertStatus(201);

        // Capped at the 10,000 GPU line, never spilling onto the processor.
        $this->assertEqualsWithDelta(5000.0, Order::latest()->first()->discount, 0.01);
    }

    public function test_min_spend_is_measured_against_the_qualifying_lines(): void
    {
        $coupon = $this->coupon(['min_spend' => 15000, 'scope' => Coupon::SCOPE_CATEGORIES]);
        $coupon->categories()->attach($this->gpus->id);

        $user = User::factory()->create();
        // 30,000 cart, but only 10,000 of it qualifies.
        $this->fill($user, [[$this->gpu, 1], [$this->cpu, 1]]);

        $this->checkout($user, 'SAVE10')
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Minimum spend of ৳15,000 on qualifying items required to use this coupon.',
            ]);
    }

    public function test_the_preview_endpoint_agrees_with_checkout(): void
    {
        $coupon = $this->coupon(['scope' => Coupon::SCOPE_CATEGORIES]);
        $coupon->categories()->attach($this->gpus->id);

        $user = User::factory()->create();
        $this->fill($user, [[$this->gpu, 1], [$this->cpu, 1]]);

        $response = $this->actingAs($user)->postJson('/api/coupons/apply', ['code' => 'SAVE10']);
        $response->assertStatus(200);

        $this->assertEqualsWithDelta(1000.0, $response->json('data.discount'), 0.01);

        $this->checkout($user, 'SAVE10')->assertStatus(201);
        $this->assertEqualsWithDelta(1000.0, Order::latest()->first()->discount, 0.01);
    }

    public function test_switching_a_coupon_back_to_the_whole_order_clears_its_restriction(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/coupons', [
            'code' => 'SCOPED', 'discount_type' => 'percent', 'discount_value' => 10,
            'scope' => Coupon::SCOPE_CATEGORIES, 'category_ids' => [$this->gpus->id],
        ])->assertStatus(201);

        $coupon = Coupon::findByCode('SCOPED');
        $this->assertCount(1, $coupon->categories);

        $this->actingAs($admin)->patchJson("/api/admin/coupons/{$coupon->id}", [
            'code' => 'SCOPED', 'discount_type' => 'percent', 'discount_value' => 10,
            'scope' => Coupon::SCOPE_ALL,
        ])->assertStatus(200);

        $this->assertCount(0, $coupon->fresh()->categories, 'a stale restriction survived');
    }
}
