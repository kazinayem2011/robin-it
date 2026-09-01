<?php

namespace Tests\Feature\Stock;

use App\Exceptions\StorefrontException;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One product's whole life on the shelf, in order.
 *
 * The individual transitions are each pinned elsewhere — a delivery, a
 * checkout, a cancellation, a conversion. What is checked here is the sequence:
 * enter a product with stock, edit it, receive more, sell some, edit it again,
 * adjust it, cancel the order. Every one of those has to leave the balance
 * equal to the sum of its ledger, and every edit of the product itself has to
 * leave the shelf exactly where it was.
 *
 * The worry this answers is a fair one: `stock_quantity` is a cached balance on
 * a row that a dozen screens can write to, and a form that reset it would be
 * silent — the number would simply be wrong from then on.
 */
class ProductStockLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function category(): Category
    {
        return Category::firstOrCreate(['slug' => 'ram'], ['name' => 'RAM', 'is_active' => true]);
    }

    /**
     * The invariant, asserted after every step: what the row says it holds is
     * what the ledger says it holds.
     */
    private function assertReconciles(Product $product, string $step): void
    {
        $product = $product->fresh();
        $check = $this->stock()->verify($product);

        $this->assertSame(
            $check['expected'],
            $check['actual'],
            "After {$step}: the row holds {$check['actual']} but the ledger accounts for {$check['expected']}."
        );

        foreach ($product->variants()->where('is_active', true)->get() as $variant) {
            $v = $this->stock()->verify($product, $variant);
            $this->assertSame(
                $v['expected'],
                $v['actual'],
                "After {$step}: option {$variant->sku} holds {$v['actual']} but its ledger accounts for {$v['expected']}."
            );
        }

        if ($product->has_variants) {
            $this->assertSame(
                (int) $product->variants()->where('is_active', true)->sum('stock_quantity'),
                (int) $product->stock_quantity,
                "After {$step}: the product total does not equal the sum of its options."
            );
        }
    }

    private function placeOrder(User $customer, Product $product, int $qty, ?int $variantId = null): Order
    {
        $payload = ['product_id' => $product->id, 'quantity' => $qty];

        if ($variantId) {
            $payload['product_variant_id'] = $variantId;
        }

        $this->actingAs($customer)->postJson('/api/cart', $payload)->assertStatus(200);

        $this->actingAs($customer)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45, Road 7', 'city' => 'Dhaka',
        ])->assertStatus(201);

        return Order::where('user_id', $customer->id)->latest()->first();
    }

    // ───────────────────────────────────────────────── a single-stock product

    public function test_the_whole_life_of_a_single_products_shelf(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();

        // 1. entered with five already on the shelf
        $this->actingAs($admin)->postJson('/api/admin/products', [
            'name' => 'Corsair Vengeance 16GB',
            'category_id' => $this->category()->id,
            'price' => 10500,
            'stock_quantity' => 5,
        ])->assertCreated();

        $product = Product::firstWhere('name', 'Corsair Vengeance 16GB');
        $this->assertSame(5, $product->stock_quantity);
        $this->assertReconciles($product, 'creation with an opening balance');

        // 2. the product is edited — a price change must not touch the shelf
        $this->actingAs($admin)
            ->patchJson("/api/admin/products/{$product->id}", ['price' => 9900])
            ->assertOk();
        $this->assertSame(5, $product->fresh()->stock_quantity, 'A price edit moved stock.');
        $this->assertReconciles($product, 'a price edit');

        // 3. and a quantity sent deliberately on an edit is refused outright
        $this->actingAs($admin)
            ->patchJson("/api/admin/products/{$product->id}", ['stock_quantity' => 99])
            ->assertOk();
        $this->assertSame(5, $product->fresh()->stock_quantity, 'An edit wrote stock directly.');

        // 4. a delivery arrives
        $this->stock()->receive(
            ['supplier_name' => 'Star Tech', 'invoice_number' => 'INV-1'],
            [['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 9000]],
            $admin->id
        );
        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertReconciles($product, 'a delivery');

        // 5. a customer buys two
        $order = $this->placeOrder($customer, $product->fresh(), 2);
        $this->assertSame(6, $product->fresh()->stock_quantity);
        $this->assertReconciles($product, 'a sale');

        // 6. the product is edited again, after the sale
        $this->actingAs($admin)
            ->patchJson("/api/admin/products/{$product->id}", [
                'name' => 'Corsair Vengeance 16GB DDR5',
                'price' => 10200,
            ])
            ->assertOk();
        $this->assertSame(6, $product->fresh()->stock_quantity, 'An edit after a sale reset the shelf.');
        $this->assertReconciles($product, 'an edit after a sale');

        // 7. one is found damaged
        $this->stock()->adjust($product->fresh(), null, -1, 'damaged', 'Bent pins', $admin->id);
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertReconciles($product, 'an adjustment');

        // 8. the order is cancelled and the units come back
        app(OrderService::class)->updateOrderStatus($order->fresh(), 'cancelled');
        $this->assertSame(7, $product->fresh()->stock_quantity);
        $this->assertReconciles($product, 'a cancellation');

        // the ledger reads as the history of all of it
        $this->assertSame(
            [StockMovement::OPENING, StockMovement::PURCHASE, StockMovement::SALE,
                StockMovement::ADJUSTMENT, StockMovement::CANCELLATION],
            StockMovement::where('product_id', $product->id)->orderBy('id')->pluck('type')->all()
        );
    }

    // ──────────────────────────────────────────────────── a product in options

    public function test_the_whole_life_of_a_variant_products_shelf(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();

        // 1. entered with stock against each option
        $this->actingAs($admin)->postJson('/api/admin/products', [
            'name' => 'Corsair Vengeance DDR5',
            'category_id' => $this->category()->id,
            'price' => 10500,
            'has_variants' => true,
            'variant_attributes' => ['Capacity'],
            'variants' => [
                ['name' => '16GB', 'options' => ['Capacity' => '16GB'], 'sku' => 'CV-16',
                    'price' => 10500, 'is_active' => true, 'opening_stock' => 4],
                ['name' => '32GB', 'options' => ['Capacity' => '32GB'], 'sku' => 'CV-32',
                    'price' => 18900, 'is_active' => true, 'opening_stock' => 2],
            ],
        ])->assertCreated();

        $product = Product::firstWhere('name', 'Corsair Vengeance DDR5');
        $small = ProductVariant::firstWhere('sku', 'CV-16');
        $large = ProductVariant::firstWhere('sku', 'CV-32');

        $this->assertSame(6, $product->stock_quantity);
        $this->assertSame([4, 2], [$small->stock_quantity, $large->stock_quantity]);
        $this->assertReconciles($product, 'creation with per-option opening balances');

        // 2. editing the product leaves every option alone
        $this->actingAs($admin)
            ->patchJson("/api/admin/products/{$product->id}", ['price' => 10000])
            ->assertOk();
        $this->assertSame(6, $product->fresh()->stock_quantity);
        $this->assertReconciles($product, 'a price edit');

        // 3. a delivery into one option
        $this->stock()->receive(
            ['supplier_name' => 'Star Tech'],
            [['product_id' => $product->id, 'product_variant_id' => $small->id, 'quantity' => 5]],
            $admin->id
        );
        $this->assertSame(9, $small->fresh()->stock_quantity);
        $this->assertSame(11, $product->fresh()->stock_quantity);
        $this->assertReconciles($product, 'a delivery into one option');

        // 4. a customer buys the other option
        $order = $this->placeOrder($customer, $product->fresh(), 2, $large->id);
        $this->assertSame(0, $large->fresh()->stock_quantity);
        $this->assertSame(9, $product->fresh()->stock_quantity);
        $this->assertReconciles($product, 'a sale of one option');

        // 5. the options are edited — a price change moves nothing
        $this->actingAs($admin)->patchJson("/api/admin/products/{$product->id}", [
            'has_variants' => true,
            'variant_attributes' => ['Capacity'],
            'variants' => [
                ['id' => $small->id, 'name' => '16GB', 'options' => ['Capacity' => '16GB'],
                    'sku' => 'CV-16', 'price' => 11000, 'is_active' => true],
                ['id' => $large->id, 'name' => '32GB', 'options' => ['Capacity' => '32GB'],
                    'sku' => 'CV-32', 'price' => 19500, 'is_active' => true],
            ],
        ])->assertOk();

        $this->assertSame(9, $small->fresh()->stock_quantity, 'Editing an option moved its stock.');
        $this->assertSame(0, $large->fresh()->stock_quantity);
        $this->assertSame(9, $product->fresh()->stock_quantity);
        $this->assertReconciles($product, 'an edit of the options');

        // 6. the order is cancelled
        app(OrderService::class)->updateOrderStatus($order->fresh(), 'cancelled');
        $this->assertSame(2, $large->fresh()->stock_quantity);
        $this->assertSame(11, $product->fresh()->stock_quantity);
        $this->assertReconciles($product, 'a cancellation');
    }

    /**
     * An opening quantity is for stock already on the shelf. Entering the same
     * product twice would be two products, not a bigger pile — but a second
     * *delivery* of the same product must add, not replace.
     */
    public function test_a_second_delivery_adds_rather_than_replaces(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/products', [
            'name' => 'Kingston Fury', 'category_id' => $this->category()->id,
            'price' => 8000, 'stock_quantity' => 2,
        ])->assertCreated();

        $product = Product::firstWhere('name', 'Kingston Fury');

        foreach ([3, 4] as $qty) {
            $this->stock()->receive([], [['product_id' => $product->id, 'quantity' => $qty]], $admin->id);
        }

        $this->assertSame(9, $product->fresh()->stock_quantity);
        $this->assertReconciles($product, 'two deliveries');
    }

    /** Nothing may drive the shelf below zero without pre-orders being allowed. */
    public function test_the_shelf_cannot_go_negative(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/products', [
            'name' => 'TeamGroup Elite', 'category_id' => $this->category()->id,
            'price' => 6000, 'stock_quantity' => 1,
        ])->assertCreated();

        $product = Product::firstWhere('name', 'TeamGroup Elite');

        $this->expectException(StorefrontException::class);
        $this->stock()->adjust($product, null, -5, 'damaged', 'Dropped the box', $admin->id);
    }

    // ───────────────────────────── correcting a figure that was entered wrong

    /**
     * A miscount is not a delivery.
     *
     * Entering the opening stock wrong is an ordinary mistake — five typed
     * where there are eight — and correcting it must not require inventing a
     * purchase that never happened. An adjustment does it, in either
     * direction, and says why on the ledger.
     */
    public function test_a_wrong_opening_figure_is_corrected_by_adjustment_not_a_purchase(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/products', [
            'name' => 'Crucial Pro 32GB', 'category_id' => $this->category()->id,
            'price' => 15000, 'stock_quantity' => 5,
        ])->assertCreated();

        $product = Product::firstWhere('name', 'Crucial Pro 32GB');

        // Counted the shelf: there are eight, not five.
        $this->actingAs($admin)->postJson('/api/admin/stock/adjust', [
            'product_id' => $product->id,
            'quantity' => 3,
            'reason' => 'stock_take',
            'note' => 'Recount after entering the product',
        ])->assertOk();

        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertReconciles($product, 'an upward correction');

        // And the other way, for a figure entered too high.
        $this->actingAs($admin)->postJson('/api/admin/stock/adjust', [
            'product_id' => $product->id,
            'quantity' => -2,
            'reason' => 'stock_take',
            'note' => 'Two were already sold at the counter',
        ])->assertOk();

        $this->assertSame(6, $product->fresh()->stock_quantity);
        $this->assertReconciles($product, 'a downward correction');

        // No purchase was invented to do it.
        $this->assertSame(
            0,
            StockMovement::where('product_id', $product->id)
                ->where('type', StockMovement::PURCHASE)->count()
        );
        $this->assertSame(
            'stock_take',
            StockMovement::where('product_id', $product->id)
                ->where('type', StockMovement::ADJUSTMENT)->first()->reason
        );
    }

    /** The same correction through the count sheet, which is the usual route. */
    public function test_a_count_sheet_corrects_the_figure_too(): void
    {
        $admin = $this->admin();
        $store = Store::create([
            'name' => 'Flagship', 'branch_type' => 'Showroom', 'city' => 'Dhaka',
            'address' => 'Elephant Road', 'phone' => '+880 1700-000000',
            'opening_hours' => '10-8', 'holds_stock' => true,
            'fulfils_online' => true, 'is_active' => true,
        ]);

        $this->actingAs($admin)->postJson('/api/admin/products', [
            'name' => 'ADATA XPG 16GB', 'category_id' => $this->category()->id,
            'price' => 9000, 'stock_quantity' => 5,
        ])->assertCreated();

        $product = Product::firstWhere('name', 'ADATA XPG 16GB');

        $this->actingAs($admin)->postJson('/api/admin/stock/count', [
            'store_id' => $store->id,
            'lines' => [['product_id' => $product->id, 'counted_quantity' => 8]],
        ])->assertStatus(201);

        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertReconciles($product, 'a stock take');
    }

    /**
     * An opening balance is written once. Re-entering the same product is a
     * second product, so there is no path that quietly doubles a shelf.
     */
    public function test_the_opening_balance_is_written_once(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/products', [
            'name' => 'Patriot Viper', 'category_id' => $this->category()->id,
            'price' => 7000, 'stock_quantity' => 4,
        ])->assertCreated();

        $product = Product::firstWhere('name', 'Patriot Viper');

        $this->actingAs($admin)
            ->patchJson("/api/admin/products/{$product->id}", ['price' => 6800])
            ->assertOk();

        $this->assertSame(
            1,
            StockMovement::where('product_id', $product->id)
                ->where('type', StockMovement::OPENING)->count()
        );
        $this->assertSame(4, $product->fresh()->stock_quantity);
    }
}
