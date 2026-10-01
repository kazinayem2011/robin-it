<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One serial typed on two lines of the same delivery.
 *
 * Each line was checked on its own, so the number passed both checks: both
 * units went on the shelf, one serial was saved, and the other unit had none.
 * Found receiving a purchase order on live.
 */
class SerialAcrossLinesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Store $branch;

    private Product $black;

    private Product $white;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => Roles::OWNER, 'is_active' => true]);
        $this->branch = Store::create([
            'name' => 'Multiplan', 'city' => 'Dhaka', 'address' => 'Road 1', 'phone' => '01711000000',
            'is_active' => true, 'holds_stock' => true, 'fulfils_online' => true, 'sort_order' => 1,
        ]);
        $category = Category::create(['name' => 'Phone', 'slug' => 'phone', 'is_active' => true]);
        foreach (['black' => 'Phone Black', 'white' => 'Phone White'] as $key => $name) {
            $this->{$key} = Product::create([
                'category_id' => $category->id, 'name' => $name, 'slug' => str($name)->slug(),
                'price' => 90000, 'stock_quantity' => 0, 'is_active' => true, 'warranty_months' => 12,
            ]);
        }
    }

    public function test_a_delivery_refuses_one_serial_on_two_lines(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/stock/receipts', [
            'store_id' => $this->branch->id,
            'lines' => [
                ['product_id' => $this->black->id, 'quantity' => 1, 'unit_cost' => 80000, 'serials' => 'SN-1'],
                ['product_id' => $this->white->id, 'quantity' => 1, 'unit_cost' => 80000, 'serials' => 'SN-1'],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'SN-1 is typed for both Phone Black and Phone White. Each box has its own serial number.');

        $this->assertSame(0, $this->black->fresh()->stock_quantity);
        $this->assertSame(0, $this->white->fresh()->stock_quantity);
        $this->assertSame(0, ProductSerial::count());
    }

    public function test_a_purchase_order_delivery_refuses_it_too(): void
    {
        $supplier = Supplier::create(['name' => 'Smart', 'is_active' => true]);
        $order = app(PurchaseOrderService::class)->save(null, $supplier, $this->admin, [
            ['product_id' => $this->black->id, 'quantity' => 1, 'unit_cost' => 80000],
            ['product_id' => $this->white->id, 'quantity' => 1, 'unit_cost' => 80000],
        ]);
        $items = $order->items()->orderBy('id')->pluck('id');

        $this->actingAs($this->admin)->postJson("/api/admin/purchase-orders/{$order->id}/receive", [
            'store_id' => $this->branch->id,
            'lines' => [
                ['purchase_order_item_id' => $items[0], 'quantity' => 1, 'serials' => 'SN-2'],
                ['purchase_order_item_id' => $items[1], 'quantity' => 1, 'serials' => 'SN-2'],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'SN-2 is typed for both Phone Black and Phone White. Each box has its own serial number.');

        $this->assertSame(0, $this->black->fresh()->stock_quantity);
        $this->assertSame(0, $order->items()->sum('quantity_received'));
        $this->assertSame(0, ProductSerial::count());
    }

    public function test_different_serials_on_each_line_still_go_through(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/stock/receipts', [
            'store_id' => $this->branch->id,
            'lines' => [
                ['product_id' => $this->black->id, 'quantity' => 1, 'unit_cost' => 80000, 'serials' => 'SN-3'],
                ['product_id' => $this->white->id, 'quantity' => 1, 'unit_cost' => 80000, 'serials' => 'SN-4'],
            ],
        ])->assertStatus(201);

        $this->assertSame(2, ProductSerial::count());
    }
}
