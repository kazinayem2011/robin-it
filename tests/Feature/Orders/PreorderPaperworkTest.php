<?php

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paperwork for an order that includes pre-ordered lines.
 *
 * An order mixing stock and pre-order items is not one shipment, and an invoice
 * that does not say so tells the customer everything is on its way.
 */
class PreorderPaperworkTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, array $attributes = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'gpu'], ['name' => 'GPU', 'is_active' => true]);

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'price' => 50000,
            'stock_quantity' => 0,
            'is_active' => true,
        ], $attributes));
    }

    private function buy(User $user, array $lines): Order
    {
        foreach ($lines as [$product, $quantity]) {
            $this->actingAs($user)->postJson('/api/cart', [
                'product_id' => $product->id,
                'quantity' => $quantity,
            ])->assertSuccessful();
        }

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        return Order::where('user_id', $user->id)->latest()->firstOrFail();
    }

    public function test_a_line_sold_from_stock_is_not_marked(): void
    {
        $inStock = $this->product('On the shelf');
        app(StockService::class)->receive([], [['product_id' => $inStock->id, 'quantity' => 5]]);

        $order = $this->buy(User::factory()->create(), [[$inStock, 2]]);

        $this->assertFalse($order->items->first()->wasPreordered());
    }

    public function test_a_line_sold_ahead_of_the_delivery_is_marked(): void
    {
        $preorder = $this->product('Not yet landed', [
            'allow_preorder' => true, 'preorder_limit' => 10,
        ]);

        $order = $this->buy(User::factory()->create(), [[$preorder, 3]]);

        $this->assertTrue($order->items->first()->wasPreordered());
    }

    /** The case the invoice exists to disambiguate. */
    public function test_a_mixed_order_marks_only_the_line_that_waits(): void
    {
        $inStock = $this->product('On the shelf');
        app(StockService::class)->receive([], [['product_id' => $inStock->id, 'quantity' => 5]]);

        $preorder = $this->product('Not yet landed', [
            'allow_preorder' => true, 'preorder_limit' => 10,
        ]);

        $order = $this->buy(User::factory()->create(), [[$inStock, 1], [$preorder, 1]]);

        $marked = $order->items->filter->wasPreordered()->pluck('product_name');

        $this->assertCount(1, $marked);
        $this->assertSame('Not yet landed', $marked->first());
    }

    /**
     * The mark describes what happened when the order was placed, so it must
     * not disappear once the shelf is restocked.
     */
    public function test_the_mark_survives_the_delivery_landing(): void
    {
        $preorder = $this->product('Not yet landed', [
            'allow_preorder' => true, 'preorder_limit' => 10,
        ]);

        $order = $this->buy(User::factory()->create(), [[$preorder, 2]]);

        app(StockService::class)->receive([], [['product_id' => $preorder->id, 'quantity' => 20]]);

        $this->assertGreaterThan(0, $preorder->fresh()->stock_quantity);
        $this->assertTrue($order->items->first()->fresh()->wasPreordered());
    }

    public function test_the_invoice_says_so(): void
    {
        $user = User::factory()->create();
        $preorder = $this->product('Not yet landed', [
            'allow_preorder' => true, 'preorder_limit' => 10,
        ]);

        $order = $this->buy($user, [[$preorder, 1]]);

        $this->actingAs($user)
            ->get("/orders/{$order->id}/invoice")
            ->assertStatus(200)
            ->assertSee('pre-order', false);
    }
}
