<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock that no movement explains.
 *
 * The shop had made no purchases and the stock screen still showed quantities,
 * because several seeders wrote `stock_quantity` straight onto the product row.
 * Nothing was behind those numbers: History was empty and no branch held the
 * units, so they could not be picked, counted or transferred.
 */
class ReconcileOpeningStockTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $quantity): Product
    {
        $category = Category::create(['name' => 'Cooler', 'slug' => 'cooler', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => '100% Copper Cooler',
            'slug' => 'copper-cooler',
            'price' => 850,
            'stock_quantity' => $quantity,
            'is_active' => true,
        ]);
    }

    private function store(): Store
    {
        return Store::create([
            'name' => 'Warehouse',
            'branch_type' => 'Warehouse',
            'city' => 'Dhaka',
            'address' => '1 Elephant Road, Dhaka',
            'phone' => '+880 1700-000000',
            'opening_hours' => '10:00 AM - 08:00 PM',
            'holds_stock' => true,
            'fulfils_online' => true,
            'is_active' => true,
        ]);
    }

    public function test_unexplained_stock_gets_an_opening_balance(): void
    {
        $store = $this->store();
        $product = $this->product(6);

        $this->artisan('stock:reconcile-opening', ['--no-interaction' => true])
            ->assertSuccessful();

        $movement = StockMovement::where('product_id', $product->id)->sole();

        $this->assertSame(StockMovement::OPENING, $movement->type);
        $this->assertSame(6, $movement->quantity);
        $this->assertSame(6, $movement->balance_after);

        // The balance is explained, not moved.
        $this->assertSame(6, $product->fresh()->stock_quantity);

        // And the units are somewhere a picker can find them.
        $this->assertSame(6, ProductStock::where('product_id', $product->id)
            ->where('store_id', $store->id)
            ->value('quantity'));
    }

    public function test_zero_clears_it_instead(): void
    {
        $this->store();
        $product = $this->product(6);

        $this->artisan('stock:reconcile-opening', ['--zero' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
    }

    /** Stock with a real history is somebody else's record; leave it alone. */
    public function test_stock_that_already_has_a_movement_is_untouched(): void
    {
        $this->store();
        $product = $this->product(6);

        StockMovement::create([
            'product_id' => $product->id,
            'quantity' => 6,
            'type' => StockMovement::PURCHASE,
            'balance_after' => 6,
        ]);

        $this->artisan('stock:reconcile-opening', ['--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame(1, StockMovement::where('product_id', $product->id)->count());
    }

    /**
     * The bug in the first version: --zero selected rows with no movements, so
     * once the default run had written an opening balance the command could no
     * longer reach its own output. An opening balance is a claim about stock,
     * not evidence of it — recording one against seed data makes the fiction
     * more convincing, and there was then no way back.
     */
    public function test_zero_can_undo_an_opening_balance_this_command_wrote(): void
    {
        $store = $this->store();
        $product = $this->product(6);

        $this->artisan('stock:reconcile-opening', ['--no-interaction' => true]);
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->count());

        $this->artisan('stock:reconcile-opening', ['--zero' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame(0, $product->fresh()->stock_quantity);

        // The claim is withdrawn, not left standing against a zero balance.
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
        $this->assertSame(0, ProductStock::where('product_id', $product->id)
            ->where('store_id', $store->id)->count());
    }

    /** A seeder's own opening entry is the same fiction, and just as clearable. */
    public function test_zero_clears_stock_a_seeder_opened(): void
    {
        $this->store();
        $product = $this->product(6);

        StockMovement::create([
            'product_id' => $product->id,
            'quantity' => 6,
            'type' => StockMovement::OPENING,
            'balance_after' => 6,
            'note' => 'Seeded placeholder stock — replace with a real delivery',
        ]);

        $this->artisan('stock:reconcile-opening', ['--zero' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
    }

    /**
     * The line that has to hold: a delivery somebody actually received is
     * never cleared, and its opening balance is not deleted along with it.
     */
    public function test_stock_with_a_purchase_survives_zero(): void
    {
        $this->store();
        $product = $this->product(6);

        StockMovement::create([
            'product_id' => $product->id,
            'quantity' => 6,
            'type' => StockMovement::OPENING,
            'balance_after' => 6,
        ]);
        StockMovement::create([
            'product_id' => $product->id,
            'quantity' => 6,
            'type' => StockMovement::PURCHASE,
            'balance_after' => 6,
        ]);

        $this->artisan('stock:reconcile-opening', ['--zero' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame(6, $product->fresh()->stock_quantity);
        $this->assertSame(2, StockMovement::where('product_id', $product->id)->count());
    }

    /** Nor is a second opening balance stacked on top of the first. */
    public function test_the_default_run_never_opens_the_same_stock_twice(): void
    {
        $this->store();
        $product = $this->product(6);

        $this->artisan('stock:reconcile-opening', ['--no-interaction' => true]);
        $this->artisan('stock:reconcile-opening', ['--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame(1, StockMovement::where('product_id', $product->id)->count());
        $this->assertSame(6, $product->fresh()->stock_quantity);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->store();
        $product = $this->product(6);

        $this->artisan('stock:reconcile-opening', ['--dry-run' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
    }

    /**
     * A product with no stock has nothing to explain — it must not pick up an
     * opening movement of zero, which would read as a delivery of nothing.
     */
    public function test_products_without_stock_are_ignored(): void
    {
        $this->store();
        $product = $this->product(0);

        $this->artisan('stock:reconcile-opening', ['--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
    }
}
