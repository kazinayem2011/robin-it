<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suppliers as records rather than typed strings.
 *
 * "Star Tech", "Star Tech Ltd" and "star tech" used to be three different
 * suppliers to every report, and there was no way to look up who to call about
 * a faulty batch.
 */
class SupplierTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function product(): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id, 'name' => 'Test CPU',
            'slug' => 'test-cpu-'.uniqid(), 'price' => 1000,
            'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    public function test_an_admin_can_add_a_supplier(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/stock/suppliers', [
            'name' => 'Star Tech Ltd',
            'phone' => '01711223344',
        ])->assertStatus(201);

        $this->assertDatabaseHas('suppliers', ['name' => 'Star Tech Ltd', 'is_active' => true]);
    }

    public function test_supplier_names_are_unique(): void
    {
        Supplier::create(['name' => 'Star Tech Ltd']);

        $this->actingAs($this->admin())->postJson('/api/admin/stock/suppliers', [
            'name' => 'Star Tech Ltd',
        ])->assertStatus(422);
    }

    public function test_a_delivery_records_which_supplier_it_came_from(): void
    {
        $supplier = Supplier::create(['name' => 'Star Tech Ltd']);
        $product = $this->product();

        $receipt = app(StockService::class)->receive(
            ['supplier_id' => $supplier->id],
            [['product_id' => $product->id, 'quantity' => 5]]
        );

        $this->assertSame($supplier->id, $receipt->supplier_id);
        // The name is frozen alongside, so the delivery still reads correctly
        // if the supplier record is later removed.
        $this->assertSame('Star Tech Ltd', $receipt->supplier_name);
    }

    /** Typing a new supplier into the delivery form should not be refused. */
    public function test_a_supplier_typed_during_a_delivery_is_created(): void
    {
        $product = $this->product();

        $receipt = app(StockService::class)->receive(
            ['supplier_name' => 'Brand New Supplier'],
            [['product_id' => $product->id, 'quantity' => 3]]
        );

        $this->assertNotNull($receipt->supplier_id);
        $this->assertDatabaseHas('suppliers', ['name' => 'Brand New Supplier']);
    }

    public function test_a_differently_cased_name_reuses_the_same_supplier(): void
    {
        $existing = Supplier::create(['name' => 'Star Tech Ltd']);
        $product = $this->product();

        $receipt = app(StockService::class)->receive(
            ['supplier_name' => 'star tech ltd'],
            [['product_id' => $product->id, 'quantity' => 2]]
        );

        $this->assertSame($existing->id, $receipt->supplier_id, 'a duplicate supplier was created');
        $this->assertSame(1, Supplier::count());
    }

    /**
     * Deleting a supplier that has supplied things would erase the record of
     * who supplied them.
     */
    public function test_a_supplier_with_deliveries_is_deactivated_not_deleted(): void
    {
        $supplier = Supplier::create(['name' => 'Star Tech Ltd']);
        $product = $this->product();

        app(StockService::class)->receive(
            ['supplier_id' => $supplier->id],
            [['product_id' => $product->id, 'quantity' => 4]]
        );

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/stock/suppliers/{$supplier->id}")
            ->assertStatus(200);

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'is_active' => false]);
        $this->assertSame(1, StockReceipt::whereNotNull('supplier_id')->count());
    }

    public function test_an_unused_supplier_can_be_removed_outright(): void
    {
        $supplier = Supplier::create(['name' => 'Never Used']);

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/stock/suppliers/{$supplier->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }

    public function test_only_an_admin_can_manage_suppliers(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson('/api/admin/stock/suppliers', ['name' => 'Sneaky Supplier']);

        $this->assertContains($response->status(), [302, 403]);
        $this->assertDatabaseMissing('suppliers', ['name' => 'Sneaky Supplier']);
    }

    public function test_the_stock_screen_offers_the_supplier_list(): void
    {
        Supplier::create(['name' => 'Star Tech Ltd']);
        Supplier::create(['name' => 'Retired One', 'is_active' => false]);

        $response = $this->actingAs($this->admin())->get('/admin/stock');
        $response->assertStatus(200);

        $names = collect($response->viewData('page')['props']['suppliers'])->pluck('name');

        $this->assertContains('Star Tech Ltd', $names);
        $this->assertNotContains('Retired One', $names, 'a retired supplier was offered');
    }
}
