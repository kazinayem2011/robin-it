<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ProductVariantService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Buying a variant product.
 *
 * Stock is held per option, so every check along the way — cart, checkout,
 * cancellation — has to be about the option the shopper chose, not the product's
 * overall total. One option being in stock says nothing about another.
 */
class VariantCommerceTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $small;

    private ProductVariant $large;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::firstOrCreate(['slug' => 'ram'], ['name' => 'RAM', 'is_active' => true]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kingston Fury Beast',
            'slug' => 'fury-'.uniqid(),
            'price' => 4200,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        app(StockService::class)->receive([], [['product_id' => $this->product->id, 'quantity' => 10]]);

        app(ProductVariantService::class)->convertToVariants($this->product->fresh(), ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'price' => 4200, 'opening_stock' => 6],
            ['options' => ['Capacity' => '32GB'], 'price' => 8200, 'opening_stock' => 4],
        ]);

        $this->product = $this->product->fresh('variants');
        $this->small = $this->product->variants->firstWhere('name', '16GB');
        $this->large = $this->product->variants->firstWhere('name', '32GB');
    }

    private function addToCart(User $user, ?int $variantId, int $qty = 1)
    {
        return $this->actingAs($user)->postJson('/cart-api', array_filter([
            'product_id' => $this->product->id,
            'product_variant_id' => $variantId,
            'quantity' => $qty,
        ]));
    }

    private function checkout(User $user)
    {
        return $this->actingAs($user)->postJson('/checkout-api', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ]);
    }

    public function test_a_variant_product_cannot_be_added_without_choosing_an_option(): void
    {
        $this->addToCart(User::factory()->create(), null, 2)->assertStatus(422);

        $this->assertSame(6, $this->small->fresh()->stock_quantity);
        $this->assertSame(4, $this->large->fresh()->stock_quantity);
    }

    public function test_buying_an_option_only_draws_down_that_option(): void
    {
        $user = User::factory()->create();

        $this->addToCart($user, $this->small->id, 2)->assertStatus(200);
        $this->checkout($user)->assertStatus(201);

        $this->assertSame(4, $this->small->fresh()->stock_quantity, 'chosen option not drawn down');
        $this->assertSame(4, $this->large->fresh()->stock_quantity, 'the other option moved');
        $this->assertSame(8, $this->product->fresh()->stock_quantity, 'parent total is not the sum');
    }

    public function test_stock_is_measured_against_the_chosen_option_not_the_product(): void
    {
        $user = User::factory()->create();

        // The product holds 10 in total, but only 4 of the 32GB option.
        $this->addToCart($user, $this->large->id, 5)->assertStatus(422);

        $this->assertSame(4, $this->large->fresh()->stock_quantity);
    }

    public function test_two_options_of_the_same_product_are_separate_cart_lines(): void
    {
        $user = User::factory()->create();

        $this->addToCart($user, $this->small->id, 2)->assertStatus(200);
        $this->addToCart($user, $this->large->id, 1)->assertStatus(200);

        $this->checkout($user)->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest()->first()->load('items');

        $this->assertCount(2, $order->items);
        $this->assertSame(4, $this->small->fresh()->stock_quantity);
        $this->assertSame(3, $this->large->fresh()->stock_quantity);
    }

    public function test_the_order_line_records_the_option_and_its_price(): void
    {
        $user = User::factory()->create();

        $this->addToCart($user, $this->large->id, 1)->assertStatus(200);
        $this->checkout($user)->assertStatus(201);

        $item = Order::where('user_id', $user->id)->latest()->first()->items->first();

        $this->assertSame($this->large->id, $item->product_variant_id);
        $this->assertSame('32GB', $item->variant_name);
        // The option's own price, not the parent's 4200.
        $this->assertEqualsWithDelta(8200.0, $item->price, 0.01);
    }

    public function test_cancelling_returns_the_units_to_the_right_option(): void
    {
        $user = User::factory()->create();

        $this->addToCart($user, $this->large->id, 3)->assertStatus(200);
        $this->checkout($user)->assertStatus(201);
        $this->assertSame(1, $this->large->fresh()->stock_quantity);

        $order = Order::where('user_id', $user->id)->latest()->first();
        app(OrderService::class)->updateOrderStatus($order, 'cancelled');

        $this->assertSame(4, $this->large->fresh()->stock_quantity, 'units did not return to the option');
        $this->assertSame(6, $this->small->fresh()->stock_quantity, 'units landed on the wrong option');
    }

    public function test_a_returned_option_goes_back_to_the_option_it_came_from(): void
    {
        $user = User::factory()->create();

        $this->addToCart($user, $this->small->id, 3)->assertStatus(200);
        $this->checkout($user)->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest()->first();
        app(OrderService::class)->updateOrderStatus($order, 'delivered');
        $order = $order->fresh('items');

        app(OrderService::class)->returnOrder($order, [
            ['order_item_id' => $order->items->first()->id, 'resellable' => 2, 'damaged' => 1],
        ]);

        $this->assertSame(5, $this->small->fresh()->stock_quantity);
        $this->assertSame(4, $this->large->fresh()->stock_quantity);
    }

    public function test_an_inactive_option_cannot_be_bought(): void
    {
        $this->large->update(['is_active' => false]);

        $this->addToCart(User::factory()->create(), $this->large->id, 1)->assertStatus(422);
    }

    public function test_an_option_from_another_product_is_rejected(): void
    {
        $other = Product::create([
            'category_id' => $this->product->category_id,
            'name' => 'Other RAM', 'slug' => 'other-'.uniqid(),
            'price' => 3000, 'stock_quantity' => 0, 'is_active' => true,
        ]);
        app(StockService::class)->receive([], [['product_id' => $other->id, 'quantity' => 5]]);

        $response = $this->actingAs(User::factory()->create())->postJson('/cart-api', [
            'product_id' => $other->id,
            'product_variant_id' => $this->small->id,
            'quantity' => 1,
        ]);

        // The single product ignores the stray option rather than mixing shelves.
        $response->assertStatus(200);
        $this->assertSame(6, $this->small->fresh()->stock_quantity);
        $this->assertSame(5, $other->fresh()->stock_quantity);
    }

    public function test_the_parent_total_tracks_the_sum_of_its_options(): void
    {
        $this->assertSame(10, $this->product->fresh()->stock_quantity);

        app(StockService::class)->receive([], [[
            'product_id' => $this->product->id,
            'product_variant_id' => $this->large->id,
            'quantity' => 5,
        ]]);

        $this->assertSame(9, $this->large->fresh()->stock_quantity);
        $this->assertSame(15, $this->product->fresh()->stock_quantity);
    }

    public function test_receiving_into_a_variant_product_requires_an_option(): void
    {
        $this->expectExceptionMessage('Choose an option');

        app(StockService::class)->receive([], [[
            'product_id' => $this->product->id,
            'quantity' => 5,
        ]]);
    }
}
