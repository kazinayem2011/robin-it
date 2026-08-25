<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ProductVariantService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selling ahead of the delivery.
 *
 * A pre-order sale is an ordinary SALE movement that takes the balance below
 * zero, and a negative balance means units owed. The ledger stays the only
 * source of truth: sum(movements) still equals stock_quantity at every point,
 * and a delivery brings the balance back up without anything special happening.
 */
class PreorderTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'gpu'], ['name' => 'Graphics Cards', 'is_active' => true]);

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'RTX 5090 Founders Edition',
            'slug' => 'rtx-5090-'.uniqid(),
            'price' => 250000,
            'stock_quantity' => 0,
            'is_active' => true,
        ], $attributes));
    }

    private function buy(Product $product, int $quantity = 1): \Illuminate\Testing\TestResponse
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/cart-api', [
            'product_id' => $product->id,
            'quantity' => $quantity,
        ])->assertSuccessful();

        return $this->actingAs($user)->postJson('/checkout-api', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ]);
    }

    private function ledgerBalance(Product $product): int
    {
        return (int) StockMovement::where('product_id', $product->id)->sum('quantity');
    }

    public function test_an_ordinary_product_still_refuses_to_be_oversold(): void
    {
        $product = $this->product();
        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 2]]);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/cart-api', [
            'product_id' => $product->id, 'quantity' => 3,
        ])->assertStatus(422);

        $this->assertSame(2, $product->fresh()->stock_quantity);
    }

    public function test_a_preorder_product_can_be_bought_with_an_empty_shelf(): void
    {
        $product = $this->product(['allow_preorder' => true, 'preorder_limit' => 10]);

        $this->buy($product, 3)->assertStatus(201);

        $this->assertSame(-3, $product->fresh()->stock_quantity, 'three units should be owed');
    }

    /** The whole point of keeping one set of books. */
    public function test_the_ledger_still_explains_the_balance(): void
    {
        $product = $this->product(['allow_preorder' => true, 'preorder_limit' => 10]);

        $this->buy($product, 4);

        $this->assertSame(-4, $this->ledgerBalance($product));
        $this->assertSame(-4, $product->fresh()->stock_quantity);
        $this->assertFalse(app(StockService::class)->verify($product->fresh())['drifted']);
    }

    public function test_the_limit_is_the_furthest_it_may_go(): void
    {
        $product = $this->product(['allow_preorder' => true, 'preorder_limit' => 2]);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/cart-api', [
            'product_id' => $product->id, 'quantity' => 3,
        ])->assertStatus(422);

        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    public function test_stock_on_hand_counts_towards_the_limit(): void
    {
        // Two on the shelf and two of headroom is four sellable, not two.
        $product = $this->product(['allow_preorder' => true, 'preorder_limit' => 2]);
        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 2]]);

        $this->buy($product->fresh(), 4)->assertStatus(201);

        $this->assertSame(-2, $product->fresh()->stock_quantity);
    }

    public function test_no_limit_means_no_cap(): void
    {
        $product = $this->product(['allow_preorder' => true, 'preorder_limit' => null]);

        // 20 rather than more only because a cart line is capped there; the
        // point is that nothing about the pre-order settings refused it.
        $this->buy($product, 20)->assertStatus(201);

        $this->assertSame(-20, $product->fresh()->stock_quantity);
        $this->assertNull($product->fresh()->sellableCeiling());
    }

    /** A delivery is the ordinary way a debt is settled. */
    public function test_receiving_stock_clears_what_is_owed_first(): void
    {
        $product = $this->product(['allow_preorder' => true, 'preorder_limit' => 10]);
        $this->buy($product, 3);

        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 5]]);

        $this->assertSame(2, $product->fresh()->stock_quantity, 'three owed, so five leaves two free');
        $this->assertSame(2, $this->ledgerBalance($product));
    }

    public function test_cancelling_a_preorder_gives_the_units_back(): void
    {
        $product = $this->product(['allow_preorder' => true, 'preorder_limit' => 10]);
        $this->buy($product, 3);

        $order = Order::latest()->first();
        app(OrderService::class)->updateOrderStatus($order, 'cancelled');

        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame(0, $this->ledgerBalance($product));
    }

    /**
     * Turning the setting off must not rewrite history. What is already owed
     * stays owed; it only stops anyone taking on more.
     */
    public function test_switching_preorder_off_leaves_existing_debt_alone(): void
    {
        $product = $this->product(['allow_preorder' => true, 'preorder_limit' => 10]);
        $this->buy($product, 3);

        $product->forceFill(['allow_preorder' => false])->save();

        $this->assertSame(-3, $product->fresh()->stock_quantity);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/cart-api', [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertStatus(422);
    }

    /**
     * Pre-order is about selling ahead of a delivery, not about inventing
     * paperwork. Nothing else may take a balance below zero.
     */
    public function test_a_write_off_cannot_use_the_preorder_allowance(): void
    {
        $product = $this->product(['allow_preorder' => true, 'preorder_limit' => 10]);

        $this->expectException(\App\Exceptions\StorefrontException::class);

        app(StockService::class)->record(
            $product, null, -1, StockMovement::WRITE_OFF, ['note' => 'damaged']
        );
    }

    public function test_an_adjustment_cannot_use_the_preorder_allowance(): void
    {
        $product = $this->product(['allow_preorder' => true, 'preorder_limit' => 10]);

        $this->expectException(\App\Exceptions\StorefrontException::class);

        app(StockService::class)->record(
            $product, null, -1, StockMovement::ADJUSTMENT, ['note' => 'recount']
        );
    }

    public function test_a_variant_option_can_be_preordered_too(): void
    {
        $product = $this->product(['allow_preorder' => true, 'preorder_limit' => 5]);

        app(ProductVariantService::class)->convertToVariants($product, ['Memory'], [
            ['options' => ['Memory' => '32GB'], 'opening_stock' => 0],
        ]);

        $variant = $product->fresh('variants')->variants->first();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/cart-api', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertSuccessful();

        $this->actingAs($user)->postJson('/checkout-api', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        $this->assertSame(-2, (int) $variant->fresh()->stock_quantity);
    }

    /** Owing units is the most urgent kind of low stock there is. */
    public function test_what_is_owed_shows_up_as_needing_reorder(): void
    {
        $product = $this->product(['allow_preorder' => true, 'preorder_limit' => 10]);
        $this->buy($product, 3);

        $this->assertTrue($product->fresh()->needsReorder());
        $this->assertTrue(
            Product::needingReorder()->whereKey($product->id)->exists(),
            'a product in debt should be on the reorder report'
        );
    }
}
