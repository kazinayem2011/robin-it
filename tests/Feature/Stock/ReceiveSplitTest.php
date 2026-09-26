<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\StockReceipt;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A delivery split between branches as it is received.
 *
 * The client's flow: ten arrive, six go to Khulna and four to Dhaka. A
 * delivery used to go to one branch only, and the purchase-order screen did
 * not even ask which.
 */
class ReceiveSplitTest extends TestCase
{
    use RefreshDatabase;

    private Store $khulna;

    private Store $dhaka;

    private User $admin;

    private Product $laptop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->khulna = $this->branch('Khulna', true, 1);
        $this->dhaka = $this->branch('Dhaka', false, 2);
        $this->admin = User::factory()->create(['role' => Roles::OWNER, 'is_active' => true]);

        $category = Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);
        $this->laptop = Product::create([
            'category_id' => $category->id, 'name' => 'ASUS Vivobook', 'slug' => 'asus-vivobook',
            'price' => 70000, 'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    private function branch(string $name, bool $primary, int $order): Store
    {
        return Store::create([
            'name' => $name, 'city' => $name, 'address' => 'Road 1', 'phone' => '01711000000',
            'is_active' => true, 'holds_stock' => true, 'fulfils_online' => $primary, 'sort_order' => $order,
        ]);
    }

    private function at(Store $store): int
    {
        return (int) ProductStock::forUnit($this->laptop->id)->where('store_id', $store->id)->value('quantity');
    }

    public function test_a_delivery_is_split_between_branches(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/stock/receipts', [
            'lines' => [[
                'product_id' => $this->laptop->id, 'quantity' => 10, 'unit_cost' => 60000,
                'branches' => [$this->khulna->id => 6, $this->dhaka->id => 4],
            ]],
        ])->assertStatus(201);

        $this->assertSame(6, $this->at($this->khulna));
        $this->assertSame(4, $this->at($this->dhaka));
        $this->assertSame(10, $this->laptop->fresh()->stock_quantity);
    }

    public function test_a_split_that_does_not_add_up_is_refused_and_nothing_moves(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/stock/receipts', [
            'lines' => [[
                'product_id' => $this->laptop->id, 'quantity' => 10, 'unit_cost' => 60000,
                'branches' => [$this->khulna->id => 6, $this->dhaka->id => 3],
            ]],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'ASUS Vivobook: 10 arrived, but the branches add up to 9. Make them add up to what arrived.');

        $this->assertSame(0, $this->laptop->fresh()->stock_quantity);
    }

    /* No split: all of it into the one branch named for the delivery. */
    public function test_without_a_split_it_all_goes_to_the_branch_chosen(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/stock/receipts', [
            'store_id' => $this->dhaka->id,
            'lines' => [['product_id' => $this->laptop->id, 'quantity' => 3, 'unit_cost' => 60000]],
        ])->assertStatus(201);

        $this->assertSame(0, $this->at($this->khulna));
        $this->assertSame(3, $this->at($this->dhaka));
    }

    /* Six to Khulna and four to Dhaka: the first six serials are Khulna's. */
    public function test_serials_follow_the_split(): void
    {
        $serials = collect(range(1, 10))->map(fn ($n) => "SN-{$n}")->implode("\n");

        $this->actingAs($this->admin)->postJson('/api/admin/stock/receipts', [
            'lines' => [[
                'product_id' => $this->laptop->id, 'quantity' => 10, 'unit_cost' => 60000,
                'branches' => [$this->khulna->id => 6, $this->dhaka->id => 4],
                'serials' => $serials,
            ]],
        ])->assertStatus(201);

        $this->assertSame(6, ProductSerial::where('store_id', $this->khulna->id)->count());
        $this->assertSame(4, ProductSerial::where('store_id', $this->dhaka->id)->count());
        $this->assertSame($this->dhaka->id, (int) ProductSerial::where('serial', 'SN-7')->value('store_id'));
    }

    /* Entered late: the day it came in, and a note, are kept. */
    public function test_the_date_and_note_are_kept(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/stock/receipts', [
            'received_on' => now()->subDays(2)->toDateString(),
            'note' => 'One box dented, signed by Karim',
            'lines' => [['product_id' => $this->laptop->id, 'quantity' => 1, 'unit_cost' => 60000]],
        ])->assertStatus(201);

        $receipt = StockReceipt::latest('id')->first();
        $this->assertSame(now()->subDays(2)->toDateString(), $receipt->received_on->toDateString());
        $this->assertSame('One box dented, signed by Karim', $receipt->note);
    }

    public function test_a_delivery_cannot_be_dated_in_the_future(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/stock/receipts', [
            'received_on' => now()->addDay()->toDateString(),
            'lines' => [['product_id' => $this->laptop->id, 'quantity' => 1, 'unit_cost' => 60000]],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'A delivery cannot be dated in the future.');

        $this->assertSame(0, $this->laptop->fresh()->stock_quantity);
    }

    /* The order's lines carry the product's name, not "#1308". */
    public function test_the_purchase_orders_page_names_each_line(): void
    {
        $supplier = Supplier::create(['name' => 'Star Supplier', 'is_active' => true]);
        app(PurchaseOrderService::class)->save(
            null, $supplier, $this->admin,
            [['product_id' => $this->laptop->id, 'quantity' => 2, 'unit_cost' => 60000]],
        );

        $this->actingAs($this->admin)->get('/admin/purchase-orders')
            ->assertInertia(fn ($page) => $page->where('orders.data.0.items.0.display_name', 'ASUS Vivobook'));
    }

    /* Against a purchase order: split, and serials recorded too. */
    public function test_a_purchase_order_delivery_is_split_and_keeps_serials(): void
    {
        $supplier = Supplier::create(['name' => 'Star Supplier', 'is_active' => true]);
        $orders = app(PurchaseOrderService::class);
        $order = $orders->save(
            null,
            $supplier,
            $this->admin,
            [['product_id' => $this->laptop->id, 'quantity' => 10, 'unit_cost' => 60000]],
            ['store_id' => $this->khulna->id],
        );
        $orders->send($order);
        $item = $order->fresh('items')->items->first();

        $this->actingAs($this->admin)->postJson("/api/admin/purchase-orders/{$order->id}/receive", [
            'lines' => [[
                'purchase_order_item_id' => $item->id, 'quantity' => 6, 'unit_cost' => 60000,
                'branches' => [$this->khulna->id => 4, $this->dhaka->id => 2],
                'serials' => "A1\nA2\nA3\nA4\nB1\nB2",
            ]],
        ])->assertOk();

        $this->assertSame(4, $this->at($this->khulna));
        $this->assertSame(2, $this->at($this->dhaka));
        $this->assertSame(PurchaseOrder::PARTIAL, $order->fresh()->status, '6 of 10 arrived');
        $this->assertSame($this->dhaka->id, (int) ProductSerial::where('serial', 'B1')->value('store_id'));
    }
}
