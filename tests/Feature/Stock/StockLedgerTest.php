<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock used to be a mutable integer any admin could overwrite. These pin the
 * rule that replaced it: the balance is the sum of the ledger, and nothing may
 * move it without leaving a row saying why.
 */
class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $stock = 0): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Ryzen 7 7800X3D',
            'slug' => 'ryzen-7-'.uniqid(),
            'price' => 45000,
            'stock_quantity' => $stock,
            'is_active' => true,
        ]);
    }

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    public function test_receiving_a_delivery_is_the_way_stock_enters(): void
    {
        $product = $this->product(0);
        $admin = User::factory()->create(['role' => 'admin']);

        $receipt = $this->stock()->receive(
            ['supplier_name' => 'Star Tech Ltd', 'invoice_number' => 'INV-99321'],
            [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 41500]],
            $admin->id
        );

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertSame(10, $receipt->total_quantity);
        $this->assertEqualsWithDelta(415000.0, $receipt->total_cost, 0.01);

        $movement = StockMovement::where('product_id', $product->id)->sole();
        $this->assertSame(StockMovement::PURCHASE, $movement->type);
        $this->assertSame(10, $movement->quantity);
        $this->assertSame(10, $movement->balance_after);
        $this->assertSame($admin->id, $movement->user_id);
    }

    public function test_the_balance_always_equals_the_sum_of_the_ledger(): void
    {
        $product = $this->product(0);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->stock()->receive([], [['product_id' => $product->id, 'quantity' => 20]], $admin->id);
        $this->stock()->adjust($product->fresh(), null, -3, 'damaged', 'Bent pins', $admin->id);
        $this->stock()->receive([], [['product_id' => $product->id, 'quantity' => 5]], $admin->id);

        $integrity = $this->stock()->verify($product->fresh());

        $this->assertSame(22, $integrity['actual']);
        $this->assertSame(22, $integrity['expected']);
        $this->assertFalse($integrity['drifted']);
    }

    public function test_stock_can_never_be_driven_below_zero(): void
    {
        $product = $this->product(0);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->stock()->receive([], [['product_id' => $product->id, 'quantity' => 2]], $admin->id);

        $this->expectExceptionMessage('Ryzen 7 7800X3D');
        $this->stock()->adjust($product->fresh(), null, -5, 'lost', null, $admin->id);
    }

    public function test_an_adjustment_must_carry_a_reason(): void
    {
        $product = $this->product(0);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->stock()->receive([], [['product_id' => $product->id, 'quantity' => 5]], $admin->id);

        $this->expectExceptionMessage('Choose a reason');
        $this->stock()->adjust($product->fresh(), null, -1, 'because-i-said-so', null, $admin->id);
    }

    public function test_an_other_adjustment_must_explain_itself(): void
    {
        $product = $this->product(0);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->stock()->receive([], [['product_id' => $product->id, 'quantity' => 5]], $admin->id);

        $this->expectExceptionMessage('Explain the adjustment');
        $this->stock()->adjust($product->fresh(), null, -1, 'other', null, $admin->id);
    }

    /**
     * The defect that motivated all of this: an admin opens the edit form, a sale
     * happens, the admin saves, and the sold units are back on the shelf.
     */
    public function test_the_product_form_can_no_longer_write_stock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product(0);
        $this->stock()->receive([], [['product_id' => $product->id, 'quantity' => 6]], $admin->id);

        $this->actingAs($admin)->patchJson("/api/admin/products/{$product->id}", [
            'name' => 'Ryzen 7 7800X3D',
            'price' => 45000,
            'stock_quantity' => 999,
        ])->assertStatus(200);

        $this->assertSame(6, $product->fresh()->stock_quantity, 'the form moved stock');
    }

    /**
     * Entering a product describes a thing; it does not put one on a shelf.
     * Stock the shop already holds is received from the "Opening balance"
     * source under Purchasing, like any other delivery.
     */
    public function test_creating_a_product_leaves_the_shelf_empty(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);

        $this->actingAs($admin)->postJson('/api/admin/products', [
            'name' => 'Ryzen 9 7950X',
            'category_id' => $category->id,
            'price' => 62000,
            'stock_quantity' => 7,
        ])->assertCreated();

        $product = Product::firstWhere('name', 'Ryzen 9 7950X');

        $this->assertSame(0, $product->stock_quantity);
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
    }

    public function test_only_an_admin_can_move_stock(): void
    {
        $product = $this->product(5);
        $customer = User::factory()->create(['role' => 'customer']);

        $response = $this->actingAs($customer)->postJson('/api/admin/stock/adjust', [
            'product_id' => $product->id,
            'quantity' => 100,
            'reason' => 'stock_take',
        ]);

        $this->assertContains($response->status(), [302, 403]);
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_a_guest_cannot_receive_stock(): void
    {
        $product = $this->product(0);

        $response = $this->postJson('/api/admin/stock/receipts', [
            'lines' => [['product_id' => $product->id, 'quantity' => 500]],
        ]);

        $this->assertContains($response->status(), [302, 401, 403]);
        $this->assertSame(0, $product->fresh()->stock_quantity);
    }
}
