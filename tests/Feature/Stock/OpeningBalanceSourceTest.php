<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock the shop already held, received like anything else.
 *
 * It used to be a quantity typed on the product form when the product was
 * first entered: a second way for stock to reach the shelf, with no cost
 * against it, no document behind it and nothing to look up afterwards. It is
 * now a delivery from a source of its own — the same screen as a supplier
 * delivery, and the same paperwork — so there is one way in and the ledger can
 * be read straight through.
 */
class OpeningBalanceSourceTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $category = Category::firstOrCreate(['slug' => 'ram'], ['name' => 'RAM', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Corsair Vengeance',
            'slug' => 'corsair-'.uniqid(),
            'price' => 10500,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);
    }

    public function test_the_opening_source_exists_without_anyone_making_it(): void
    {
        $source = Supplier::openingBalance();

        $this->assertSame('opening', $source->kind);
        $this->assertTrue($source->isOpeningBalance());
        $this->assertSame(1, Supplier::where('kind', Supplier::OPENING)->count());
    }

    /** The point of the whole thing. */
    public function test_receiving_from_it_puts_stock_on_the_shelf(): void
    {
        $product = $this->product();
        $admin = User::factory()->create(['role' => 'admin']);

        app(StockService::class)->receive(
            ['supplier_id' => Supplier::openingBalance()->id, 'note' => 'Counted at opening'],
            [['product_id' => $product->id, 'quantity' => 8, 'unit_cost' => 9000]],
            $admin->id
        );

        $this->assertSame(8, $product->fresh()->stock_quantity);

        $movement = StockMovement::where('product_id', $product->id)->sole();

        // An opening balance, not a purchase — it is not something the shop
        // bought this month and must not read as one.
        $this->assertSame(StockMovement::OPENING, $movement->type);
        $this->assertSame(8, $movement->quantity);
        $this->assertEqualsWithDelta(9000.0, $movement->unit_cost, 0.01);
    }

    /** A real supplier still books a purchase. */
    public function test_a_trade_supplier_still_records_a_purchase(): void
    {
        $product = $this->product();
        $supplier = Supplier::create(['name' => 'Star Tech', 'kind' => Supplier::TRADE, 'is_active' => true]);

        app(StockService::class)->receive(
            ['supplier_id' => $supplier->id],
            [['product_id' => $product->id, 'quantity' => 4]],
        );

        $this->assertSame(
            StockMovement::PURCHASE,
            StockMovement::where('product_id', $product->id)->sole()->type
        );
    }

    /**
     * It carries a cost like any delivery, so what the shop is holding can be
     * valued. A quantity typed on the product form never could be.
     */
    public function test_an_opening_delivery_is_costed(): void
    {
        $product = $this->product();

        $receipt = app(StockService::class)->receive(
            ['supplier_id' => Supplier::openingBalance()->id],
            [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 8800]],
        );

        $this->assertSame(5, $receipt->total_quantity);
        $this->assertEqualsWithDelta(44000.0, $receipt->total_cost, 0.01);
        $this->assertSame('Opening balance', $receipt->supplier_name);
    }

    /** And it is a document, which is what the product form left behind nothing of. */
    public function test_it_leaves_a_receipt_to_look_up(): void
    {
        $product = $this->product();

        $receipt = app(StockService::class)->receive(
            ['supplier_id' => Supplier::openingBalance()->id, 'note' => 'Shelf count, 1 Sept'],
            [['product_id' => $product->id, 'quantity' => 3]],
        );

        $this->assertNotEmpty($receipt->reference);
        $this->assertSame('Shelf count, 1 Sept', $receipt->note);
        $this->assertSame(1, $receipt->items()->count());
    }

    /** Entering a product no longer puts anything on the shelf. */
    public function test_creating_a_product_leaves_the_shelf_empty(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::firstOrCreate(['slug' => 'ram'], ['name' => 'RAM', 'is_active' => true]);

        $this->actingAs($admin)->postJson('/api/admin/products', [
            'name' => 'Kingston Fury',
            'category_id' => $category->id,
            'price' => 8000,
            // Sent deliberately: an older client, or somebody trying.
            'stock_quantity' => 12,
        ])->assertCreated();

        $product = Product::firstWhere('name', 'Kingston Fury');

        $this->assertSame(0, $product->stock_quantity);
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
    }

    /** The opening source is not one of the companies the shop buys from. */
    public function test_it_is_kept_out_of_the_trade_supplier_list(): void
    {
        Supplier::create(['name' => 'Star Tech', 'kind' => Supplier::TRADE, 'is_active' => true]);

        $trade = Supplier::trade()->pluck('name')->all();

        $this->assertSame(['Star Tech'], $trade);
        $this->assertNotContains('Opening balance', $trade);
    }
}
