<?php

namespace Tests\Feature\Api;

use App\Constants\ApiEndpoints;
use App\Exceptions\StorefrontException;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkout used to decrement stock without ever checking it, so a customer could
 * order 50 units of a 2-in-stock product and drive inventory to -48.
 */
class StockEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(int $stock = 5, bool $active = true): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'cpu'],
            ['name' => 'CPU', 'is_active' => true]
        );

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Intel Core i9 14900K',
            'slug' => 'intel-core-i9-14900k-'.uniqid(),
            'price' => 50000,
            'stock_quantity' => $stock,
            'is_active' => $active,
        ]);
    }

    private function address(): array
    {
        return [
            'name' => 'Rahim Chowdhury',
            'phone' => '01712345678',
            'street_address' => 'House 45, Road 7, Gulshan 2',
            'city' => 'Dhaka',
        ];
    }

    /* Sold out: nothing to take, nothing to owe. */
    public function test_cannot_add_a_sold_out_product_to_the_cart(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(0);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertStatus(422)
            ->assertJsonPath('error', true)
            ->assertJsonPath('code', 'OUT_OF_STOCK');
    }

    /*
     * The shop's rule: with some in stock, more goes in the cart, and the
     * order owes the rest — "waiting for stock" — until the next delivery.
     */
    public function test_more_than_is_in_stock_goes_in_the_cart_when_some_is(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(2);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id,
            'quantity' => 5,
        ])->assertStatus(200);
    }

    /* Past stock is owed, but a line never goes past the per-item cap. */
    public function test_repeated_adds_cannot_accumulate_past_the_per_item_cap(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(3);
        $cap = CartService::MAX_QUANTITY_PER_ITEM;

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id, 'quantity' => $cap,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id, 'quantity' => 5,
        ]);

        $this->actingAs($user)->getJson('/api/'.ApiEndpoints::CART)
            ->assertJsonPath('data.items.0.quantity', $cap);
    }

    /* It sold out while in the cart: the quantity can no longer be raised. */
    public function test_cannot_raise_cart_quantity_once_it_sold_out(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(2);

        $itemId = $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id, 'quantity' => 1,
        ])->json('data.id');

        $product->update(['stock_quantity' => 0]);

        $this->actingAs($user)
            ->patchJson('/api/'.str_replace('{itemId}', $itemId, ApiEndpoints::CART_ITEM), ['quantity' => 9])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OUT_OF_STOCK');
    }

    public function test_inactive_product_cannot_be_added_to_cart(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(10, active: false);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertStatus(422)->assertJsonPath('code', 'PRODUCT_UNAVAILABLE');
    }

    public function test_checkout_is_refused_when_stock_ran_out_after_adding_to_cart(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(5);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id, 'quantity' => 4,
        ])->assertStatus(200);

        // Someone else buys the last of it while this cart is sitting open.
        $product->update(['stock_quantity' => 0]);

        $this->actingAs($user)
            ->postJson('/api/'.ApiEndpoints::CHECKOUT, $this->address())
            ->assertStatus(422)
            ->assertJsonPath('code', 'OUT_OF_STOCK');

        $this->assertSame(0, $product->fresh()->stock_quantity, 'Stock must not move on a refused checkout.');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_is_refused_when_a_cart_product_is_delisted(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(5);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertStatus(200);

        $product->update(['is_active' => false]);

        $this->actingAs($user)
            ->postJson('/api/'.ApiEndpoints::CHECKOUT, $this->address())
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRODUCT_UNAVAILABLE');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_stock_never_goes_negative(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(3);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id, 'quantity' => 3,
        ])->assertStatus(200);

        $this->actingAs($user)
            ->postJson('/api/'.ApiEndpoints::CHECKOUT, $this->address())
            ->assertStatus(201);

        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    public function test_two_shoppers_cannot_both_buy_the_last_unit(): void
    {
        $productA = $this->makeProduct(1);

        $cartService = app(CartService::class);
        $orderService = app(OrderService::class);

        $first = User::factory()->create();
        $second = User::factory()->create();

        $cartOne = $cartService->getOrCreateCart($first->id, null);
        $cartService->addItem($cartOne, $productA->id, 1);

        $cartTwo = $cartService->getOrCreateCart($second->id, null);
        $cartService->addItem($cartTwo, $productA->id, 1);

        // Both carts hold the single remaining unit; only one order may succeed.
        $orderService->placeOrder($cartOne, $this->address(), $first->id);

        $this->expectException(StorefrontException::class);
        $orderService->placeOrder($cartTwo, $this->address(), $second->id);
    }

    public function test_cancelling_an_order_returns_stock_to_the_shelf(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(5);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id, 'quantity' => 2,
        ])->assertStatus(200);

        $this->actingAs($user)
            ->postJson('/api/'.ApiEndpoints::CHECKOUT, $this->address())
            ->assertStatus(201);

        $this->assertSame(3, $product->fresh()->stock_quantity);

        app(OrderService::class)->updateOrderStatus(Order::first(), 'cancelled');

        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_cart_reports_items_that_became_unavailable(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(5);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id, 'quantity' => 4,
        ])->assertStatus(200);

        $product->update(['stock_quantity' => 0]);

        $response = $this->actingAs($user)->getJson('/api/'.ApiEndpoints::CART);

        // What to do about it, never how many are left.
        $response->assertStatus(200)
            ->assertJsonPath('data.issues.0.reason', 'out_of_stock')
            ->assertJsonPath('data.issues.0.requested', 4)
            ->assertJsonMissingPath('data.issues.0.available');
    }

    /*
     * Fewer on the shelf than the line asks for, on an ordinary product: the
     * cart and checkout take it and owe the rest, so the cart must not call it
     * a problem. It did, and the customer was told to reduce a quantity the
     * shop would have accepted.
     */
    public function test_cart_does_not_flag_a_line_the_shop_would_take(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(5);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id, 'quantity' => 4,
        ])->assertStatus(200);

        $product->update(['stock_quantity' => 1]);

        $this->actingAs($user)->getJson('/api/'.ApiEndpoints::CART)
            ->assertStatus(200)
            ->assertJsonPath('data.issues', []);
    }

    /* Past a pre-order limit: fewer will do, and it says so without a number. */
    public function test_cart_flags_a_pre_order_line_past_its_limit(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(0);
        $product->update(['allow_preorder' => true, 'preorder_limit' => 5]);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $product->id, 'quantity' => 4,
        ])->assertStatus(200);

        $product->update(['preorder_limit' => 2]);

        $this->actingAs($user)->getJson('/api/'.ApiEndpoints::CART)
            ->assertStatus(200)
            ->assertJsonPath('data.issues.0.reason', 'insufficient_stock')
            ->assertJsonMissingPath('data.issues.0.available');
    }
}
