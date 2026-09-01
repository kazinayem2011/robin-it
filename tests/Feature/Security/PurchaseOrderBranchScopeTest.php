<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A storekeeper stays in their own branch, on purchase orders too.
 *
 * BranchScope is the rule that a member of staff tied to a branch may only see
 * and touch that branch, and StockController asks it before every write. The
 * purchase order screens never did: `store_id` came off the request and went
 * to the service untouched, so a storekeeper confined to Uttara could raise an
 * order against Chattogram, send it, and book the goods onto shelves they have
 * nothing to do with — inventory and stock valuation for a branch they do not
 * work at, moved by somebody with no business there.
 */
class PurchaseOrderBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private Store $mine;

    private Store $theirs;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = $this->branch('Uttara', 'Dhaka');
        $this->theirs = $this->branch('Chattogram', 'Chattogram');

        $this->supplier = Supplier::create(['name' => 'Acme Distribution', 'is_active' => true]);

        $category = Category::create(['name' => 'Storage', 'slug' => 'storage', 'is_active' => true]);
        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test SSD',
            'slug' => 'test-ssd',
            'price' => 5000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);
    }

    private function branch(string $name, string $city): Store
    {
        return Store::create([
            'name' => $name,
            'city' => $city,
            'address' => "{$name} Road",
            'phone' => '+880 1700-000000',
            'opening_hours' => '10:00 AM - 08:00 PM',
            'is_active' => true,
            'holds_stock' => true,
        ]);
    }

    private function keeper(): User
    {
        return User::factory()->create(['role' => 'storekeeper', 'store_id' => $this->mine->id]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function orderFor(Store $store): PurchaseOrder
    {
        $this->actingAs($this->owner())->postJson('/api/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'store_id' => $store->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 4000]],
        ])->assertOk();

        return PurchaseOrder::latest('id')->firstOrFail();
    }

    public function test_an_order_raised_for_another_branch_is_filed_under_their_own(): void
    {
        $this->actingAs($this->keeper())->postJson('/api/admin/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'store_id' => $this->theirs->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 4000]],
        ])->assertOk();

        $this->assertSame(
            $this->mine->id,
            PurchaseOrder::latest('id')->firstOrFail()->store_id,
            'A confined storekeeper raised an order against a branch they do not work at.'
        );
    }

    public function test_another_branchs_order_cannot_be_edited(): void
    {
        $order = $this->orderFor($this->theirs);

        $this->actingAs($this->keeper())
            ->putJson("/api/admin/purchase-orders/{$order->id}", [
                'supplier_id' => $this->supplier->id,
                'lines' => [['product_id' => $this->product->id, 'quantity' => 99, 'unit_cost' => 1]],
            ])
            ->assertStatus(403);
    }

    public function test_another_branchs_order_cannot_be_sent(): void
    {
        $order = $this->orderFor($this->theirs);

        $this->actingAs($this->keeper())
            ->postJson("/api/admin/purchase-orders/{$order->id}/send")
            ->assertStatus(403);
    }

    public function test_another_branchs_order_cannot_be_cancelled(): void
    {
        $order = $this->orderFor($this->theirs);

        $this->actingAs($this->keeper())
            ->postJson("/api/admin/purchase-orders/{$order->id}/cancel")
            ->assertStatus(403);
    }

    /** The one that moves stock, and so the one that matters most. */
    public function test_another_branchs_delivery_cannot_be_booked_in(): void
    {
        $order = $this->orderFor($this->theirs);
        $this->actingAs($this->owner())->postJson("/api/admin/purchase-orders/{$order->id}/send");

        $this->actingAs($this->keeper())
            ->postJson("/api/admin/purchase-orders/{$order->id}/receive", [
                'store_id' => $this->theirs->id,
                'lines' => [[
                    'purchase_order_item_id' => $order->items()->first()->id,
                    'quantity' => 10,
                    'unit_cost' => 4000,
                ]],
            ])
            ->assertStatus(403);

        $this->assertNull(
            ProductStock::where('product_id', $this->product->id)
                ->where('store_id', $this->theirs->id)
                ->value('quantity'),
            'Units landed in a branch the storekeeper does not work at.'
        );
    }

    /** Their own branch still works, or the rule has broken the job. */
    public function test_their_own_branch_is_unaffected(): void
    {
        $order = $this->orderFor($this->mine);
        $this->actingAs($this->owner())->postJson("/api/admin/purchase-orders/{$order->id}/send");

        $this->actingAs($this->keeper())
            ->postJson("/api/admin/purchase-orders/{$order->id}/receive", [
                'store_id' => $this->mine->id,
                'lines' => [[
                    'purchase_order_item_id' => $order->items()->first()->id,
                    'quantity' => 10,
                    'unit_cost' => 4000,
                ]],
            ])
            ->assertOk();

        $this->assertSame(
            10,
            (int) ProductStock::where('product_id', $this->product->id)
                ->where('store_id', $this->mine->id)
                ->value('quantity')
        );
    }

    /** An owner is not confined and still runs the whole shop. */
    public function test_an_owner_may_still_work_any_branch(): void
    {
        $order = $this->orderFor($this->theirs);

        $this->actingAs($this->owner())
            ->postJson("/api/admin/purchase-orders/{$order->id}/send")
            ->assertOk();

        $this->actingAs($this->owner())
            ->postJson("/api/admin/purchase-orders/{$order->id}/receive", [
                'store_id' => $this->theirs->id,
                'lines' => [[
                    'purchase_order_item_id' => $order->items()->first()->id,
                    'quantity' => 10,
                    'unit_cost' => 4000,
                ]],
            ])
            ->assertOk();

        $this->assertSame(
            10,
            (int) ProductStock::where('product_id', $this->product->id)
                ->where('store_id', $this->theirs->id)
                ->value('quantity')
        );
    }
}
