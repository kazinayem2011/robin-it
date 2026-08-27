<?php

namespace Tests\Feature\Reporting;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What each sold unit cost the shop, frozen at the moment of sale.
 *
 * Purchase prices move. The ledger knows what a unit costs today; it says
 * nothing about what an order from three months ago actually earned, and once
 * the price has moved that figure is gone for good. Cost is captured the same
 * way `price` already is — written onto the line at checkout and never
 * recalculated.
 */
class OrderCostSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);
        $this->category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);
    }

    private function product(string $slug, float $price): Product
    {
        return Product::create([
            'category_id' => $this->category->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'price' => $price,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);
    }

    private function receive(Product $product, int $quantity, ?float $unitCost): void
    {
        $this->stock->receive([], [[
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
        ]]);
    }

    private function buy(Product $product, int $quantity = 1): Order
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $product->id,
            'quantity' => $quantity,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        return Order::latest('id')->first();
    }

    public function test_the_purchase_price_is_written_onto_the_order_line(): void
    {
        $product = $this->product('ryzen', 20000);
        $this->receive($product, 10, 14000);

        $order = $this->buy($product, 2);
        $item = $order->items->first();

        $this->assertSame(14000.0, (float) $item->unit_cost);
        $this->assertSame(28000.0, $item->cost_total);
        $this->assertSame(12000.0, $item->gross_profit);   // 40000 sold - 28000 cost
    }

    /** The point of the snapshot: a later delivery must not rewrite history. */
    public function test_a_later_purchase_price_does_not_change_a_past_order(): void
    {
        $product = $this->product('ryzen', 20000);
        $this->receive($product, 10, 14000);

        $order = $this->buy($product, 1);

        // The next delivery costs more.
        $this->receive($product, 10, 17500);

        $this->assertSame(14000.0, (float) $order->fresh()->items->first()->unit_cost);
        $this->assertSame(6000.0, $order->fresh()->gross_profit);
    }

    public function test_the_most_recent_purchase_price_is_the_one_used(): void
    {
        $product = $this->product('ryzen', 20000);
        $this->receive($product, 5, 14000);
        $this->receive($product, 5, 15500);

        $order = $this->buy($product, 1);

        $this->assertSame(15500.0, (float) $order->items->first()->unit_cost);
    }

    /**
     * A product that never came in through a delivery has no known cost, and a
     * guess would be worse than an admission.
     */
    public function test_an_uncosted_product_records_no_cost(): void
    {
        $product = $this->product('mystery', 20000);
        $this->receive($product, 10, null);

        $order = $this->buy($product, 1);

        $this->assertNull($order->items->first()->unit_cost);
        $this->assertNull($order->items->first()->cost_total);
        $this->assertNull($order->cost_total);
        $this->assertNull($order->gross_profit);
        $this->assertSame(1, $order->uncosted_item_count);
    }

    /** A partial total presented as a whole reads as profit that is not there. */
    public function test_one_uncosted_line_withholds_the_order_total(): void
    {
        $costed = $this->product('costed', 20000);
        $uncosted = $this->product('uncosted', 5000);
        $this->receive($costed, 10, 14000);
        $this->receive($uncosted, 10, null);

        $user = User::factory()->create();
        foreach ([$costed, $uncosted] as $p) {
            $this->actingAs($user)->postJson('/api/cart', ['product_id' => $p->id, 'quantity' => 1])
                ->assertStatus(200);
        }
        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        $order = Order::latest('id')->first();

        $this->assertNull($order->cost_total);
        $this->assertNull($order->gross_profit);
        $this->assertSame(1, $order->uncosted_item_count);
    }

    /** Delivery is excluded from profit on both sides; it is the courier's. */
    public function test_profit_ignores_the_delivery_fee(): void
    {
        $product = $this->product('ryzen', 20000);
        $this->receive($product, 10, 14000);

        $order = $this->buy($product, 1);

        $this->assertGreaterThan(0, (float) $order->shipping_fee);
        $this->assertSame(6000.0, $order->gross_profit);   // 20000 - 14000, fee untouched
    }

    /** Options hold their own stock, so they hold their own cost. */
    public function test_each_option_carries_its_own_cost(): void
    {
        $product = $this->product('ram', 9000);
        $product->update(['has_variants' => true, 'variant_attributes' => ['Size']]);

        $variants = $product->variants()->createMany([
            ['name' => '16GB', 'options' => ['Size' => '16GB'], 'price' => 9000, 'is_active' => true, 'position' => 1],
            ['name' => '32GB', 'options' => ['Size' => '32GB'], 'price' => 16000, 'is_active' => true, 'position' => 2],
        ]);

        $this->stock->receive([], [
            ['product_id' => $product->id, 'product_variant_id' => $variants[0]->id, 'quantity' => 5, 'unit_cost' => 6000],
            ['product_id' => $product->id, 'product_variant_id' => $variants[1]->id, 'quantity' => 5, 'unit_cost' => 11000],
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $product->id,
            'product_variant_id' => $variants[1]->id,
            'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        $item = OrderItem::latest('id')->first();

        $this->assertSame(11000.0, (float) $item->unit_cost, 'The 32GB option must carry its own cost.');
        $this->assertSame(5000.0, $item->gross_profit);
    }
}
