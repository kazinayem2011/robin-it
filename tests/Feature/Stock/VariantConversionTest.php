<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ProductVariantService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Switching a product between a single stock pool and per-option stock.
 *
 * The one thing that must never happen: the shop's total holding changing at the
 * moment of the switch. Every conversion moves the same units across and nets to
 * exactly zero in the ledger.
 */
class VariantConversionTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $stock = 12): Product
    {
        $category = Category::firstOrCreate(['slug' => 'ram'], ['name' => 'RAM', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Kingston Fury Beast',
            'slug' => 'fury-'.uniqid(),
            'price' => 4200,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        if ($stock > 0) {
            app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => $stock]]);
        }

        return $product->fresh();
    }

    private function variants(): ProductVariantService
    {
        return app(ProductVariantService::class);
    }

    private function totalHolding(Product $product): int
    {
        $product = $product->fresh();

        return $product->has_variants
            ? (int) ProductVariant::where('product_id', $product->id)->sum('stock_quantity')
            : (int) $product->stock_quantity;
    }

    public function test_switching_to_options_moves_the_shelf_without_changing_the_total(): void
    {
        $product = $this->product(12);

        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 5],
            ['options' => ['Capacity' => '32GB'], 'opening_stock' => 7],
        ]);

        $product = $product->fresh('variants');

        $this->assertTrue($product->has_variants);
        $this->assertSame(12, $this->totalHolding($product), 'the total holding changed');
        $this->assertSame(12, $product->stock_quantity, 'the parent total is not the sum of its options');

        $this->assertSame(5, $product->variants->firstWhere('name', '16GB')->stock_quantity);
        $this->assertSame(7, $product->variants->firstWhere('name', '32GB')->stock_quantity);
    }

    /** The conversion movements must cancel out exactly — no units created or lost. */
    public function test_the_conversion_nets_to_zero_in_the_ledger(): void
    {
        $product = $this->product(12);

        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 5],
            ['options' => ['Capacity' => '32GB'], 'opening_stock' => 7],
        ]);

        $net = (int) StockMovement::where('product_id', $product->id)
            ->where('type', StockMovement::CONVERSION)
            ->sum('quantity');

        $this->assertSame(0, $net, 'the conversion invented or destroyed stock');
    }

    public function test_an_allocation_that_does_not_match_the_shelf_is_refused(): void
    {
        $product = $this->product(12);

        $this->expectExceptionMessage('has 12 in stock but you have allocated 10');

        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 5],
            ['options' => ['Capacity' => '32GB'], 'opening_stock' => 5],
        ]);

        $this->assertSame(12, $product->fresh()->stock_quantity);
    }

    public function test_over_allocating_is_refused_too(): void
    {
        $product = $this->product(12);

        $this->expectExceptionMessage('has 12 in stock but you have allocated 20');

        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 20],
        ]);
    }

    public function test_switching_back_to_a_single_pool_collects_every_unit(): void
    {
        $product = $this->product(12);

        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 5],
            ['options' => ['Capacity' => '32GB'], 'opening_stock' => 7],
        ]);

        $this->variants()->convertToSingle($product->fresh());

        $product = $product->fresh();
        $this->assertFalse($product->has_variants);
        $this->assertSame(12, $product->stock_quantity, 'units were lost collapsing back');
        $this->assertFalse(app(StockService::class)->verify($product)['drifted']);
    }

    public function test_a_full_round_trip_preserves_the_total_exactly(): void
    {
        $product = $this->product(12);

        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 5],
            ['options' => ['Capacity' => '32GB'], 'opening_stock' => 7],
        ]);
        $this->variants()->convertToSingle($product->fresh());
        $this->variants()->convertToVariants($product->fresh(), ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 12],
        ]);

        $this->assertSame(12, $this->totalHolding($product));

        $net = (int) StockMovement::where('product_id', $product->id)
            ->where('type', StockMovement::CONVERSION)->sum('quantity');
        $this->assertSame(0, $net);
    }

    /**
     * A pending order still owes its units back to a specific shelf. Moving the
     * shelf under it would make an honest restock impossible.
     */
    public function test_conversion_is_blocked_while_an_order_is_open(): void
    {
        $product = $this->product(12);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/cart-api', ['product_id' => $product->id, 'quantity' => 2]);
        $this->actingAs($user)->postJson('/checkout-api', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        $this->expectExceptionMessage('open order(s) still contain this product');

        $this->variants()->convertToVariants($product->fresh(), ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 10],
        ]);
    }

    public function test_conversion_is_allowed_once_the_order_is_settled(): void
    {
        $product = $this->product(12);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/cart-api', ['product_id' => $product->id, 'quantity' => 2]);
        $this->actingAs($user)->postJson('/checkout-api', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ]);

        $order = Order::where('user_id', $user->id)->latest()->first();
        app(OrderService::class)->updateOrderStatus($order, 'delivered');

        $this->variants()->convertToVariants($product->fresh(), ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 10],
        ]);

        $this->assertSame(10, $this->totalHolding($product));
    }

    /** Editing labels and prices is not a stock operation. */
    public function test_editing_options_never_moves_stock(): void
    {
        $product = $this->product(12);

        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 5],
            ['options' => ['Capacity' => '32GB'], 'opening_stock' => 7],
        ]);

        $product = $product->fresh('variants');
        $first = $product->variants->firstWhere('name', '16GB');
        $second = $product->variants->firstWhere('name', '32GB');

        $this->variants()->syncVariants($product, ['Capacity'], [
            ['id' => $first->id, 'options' => ['Capacity' => '16GB'], 'price' => 4500, 'stock_quantity' => 999],
            ['id' => $second->id, 'options' => ['Capacity' => '32GB'], 'price' => 8200],
        ]);

        $this->assertSame(5, $first->fresh()->stock_quantity, 'editing an option moved its stock');
        $this->assertSame(7, $second->fresh()->stock_quantity);
        $this->assertEqualsWithDelta(4500.0, $first->fresh()->price, 0.01);
        $this->assertSame(12, $this->totalHolding($product));
    }

    /**
     * Deleting an option that holds units would destroy stock that is physically
     * on the shelf, so it is retired instead and its units stay accounted for.
     */
    public function test_removing_an_option_that_holds_stock_retires_it_instead(): void
    {
        $product = $this->product(12);

        $this->variants()->convertToVariants($product, ['Capacity'], [
            ['options' => ['Capacity' => '16GB'], 'opening_stock' => 5],
            ['options' => ['Capacity' => '32GB'], 'opening_stock' => 7],
        ]);

        $product = $product->fresh('variants');
        $keep = $product->variants->firstWhere('name', '16GB');
        $drop = $product->variants->firstWhere('name', '32GB');

        $this->variants()->syncVariants($product, ['Capacity'], [
            ['id' => $keep->id, 'options' => ['Capacity' => '16GB']],
        ]);

        $this->assertNotNull($drop->fresh(), 'an option holding stock was deleted');
        $this->assertFalse($drop->fresh()->is_active);
        $this->assertSame(7, $drop->fresh()->stock_quantity, 'its units vanished');
    }
}
