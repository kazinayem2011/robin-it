<?php

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\OrderStatusChange;
use App\Models\Product;
use App\Models\Refund;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An order's Activity: what happened to it, in order, and who did each thing.
 *
 * A laptop order on live went placed → paid → shipped → refunded → returned
 * within minutes, and the order window could say none of it: payments named
 * who took them, but nothing said who dispatched it or took it back.
 */
class OrderActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Order $order;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Roles::forget();

        $this->owner = User::factory()->create(['name' => 'Kazi Nayem']);
        $this->owner->forceFill(['role' => User::ROLE_ADMIN])->saveQuietly();

        $category = Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);
        $this->product = Product::create([
            'category_id' => $category->id, 'name' => 'ASUS Vivobook', 'slug' => 'vivobook',
            'price' => 77500, 'stock_quantity' => 13, 'is_active' => true,
        ]);

        $customer = User::factory()->create(['name' => 'Rahim Chowdhury']);

        $this->order = Order::create([
            'order_number' => 'ORD-ACTIVITY', 'user_id' => $customer->id,
            'subtotal' => 77500, 'shipping_fee' => 0, 'discount' => 0, 'total' => 77500,
            'status' => 'pending', 'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => ['name' => 'Rahim Chowdhury', 'phone' => '01712345678', 'street_address' => '19/C', 'city' => 'Dhaka'],
        ]);

        OrderItem::create([
            'order_id' => $this->order->id, 'product_id' => $this->product->id,
            'product_name' => 'ASUS Vivobook', 'quantity' => 1, 'price' => 77500, 'total' => 77500,
        ]);
    }

    private function activity(): array
    {
        return $this->actingAs($this->owner)
            ->getJson("/api/admin/orders/{$this->order->id}/activity")
            ->assertOk()
            ->json('data');
    }

    public function test_a_status_move_records_who_made_it(): void
    {
        $this->actingAs($this->owner);
        $this->order->update(['status' => 'shipped']);

        $change = OrderStatusChange::sole();
        $this->assertSame('pending', $change->from_status);
        $this->assertSame('shipped', $change->to_status);
        $this->assertSame('Kazi Nayem', $change->by_name);
    }

    public function test_saving_without_moving_the_status_records_nothing(): void
    {
        $this->order->update(['payment_status' => 'paid']);

        $this->assertSame(0, OrderStatusChange::count());
    }

    public function test_the_activity_lists_everything_in_order_with_who_did_it(): void
    {
        $store = Store::create(['name' => 'Khulna Branch', 'city' => 'Khulna', 'address' => 'KDA', 'phone' => '01711000000', 'is_active' => true, 'holds_stock' => true]);

        StockMovement::create([
            'product_id' => $this->product->id, 'store_id' => $store->id, 'quantity' => -1,
            'type' => StockMovement::SALE, 'balance_after' => 12,
            'reference_type' => Order::class, 'reference_id' => $this->order->id,
        ]);

        $this->travel(1)->minutes();
        OrderPayment::create([
            'order_id' => $this->order->id, 'amount' => 77500, 'method' => 'cash',
            'received_by_name' => 'Kazi Nayem', 'received_on' => now()->toDateString(),
        ]);

        $this->travel(1)->minutes();
        $this->actingAs($this->owner);
        $this->order->update(['status' => 'shipped']);

        $this->travel(1)->minutes();
        Refund::create([
            'order_id' => $this->order->id, 'amount' => 77500, 'method' => 'cash',
            'reason' => 'returned', 'user_id' => $this->owner->id, 'refunded_on' => now()->toDateString(),
        ]);

        $this->travel(1)->minutes();
        $this->order->update(['status' => 'returned']);
        StockMovement::create([
            'product_id' => $this->product->id, 'store_id' => $store->id, 'quantity' => 1,
            'type' => StockMovement::RETURN, 'balance_after' => 13,
            'reference_type' => Order::class, 'reference_id' => $this->order->id,
        ]);

        $rows = $this->activity();
        $titles = array_column($rows, 'title');

        $this->assertSame([
            'Order placed — ৳77,500, cash on delivery',
            'Stock: 1 × ASUS Vivobook out of Khulna Branch',
            'Paid: ৳77,500 cash',
            'Status: Pending → Shipped',
            'Refunded: ৳77,500 cash',
            'Status: Shipped → Returned',
            'Stock: 1 × ASUS Vivobook back to Khulna Branch',
        ], $titles);

        $this->assertSame('Rahim Chowdhury', $rows[0]['by']);
        $this->assertNull($rows[3]['detail'], 'no courier was set on this order');
        $this->assertSame('Kazi Nayem', $rows[3]['by']);
        $this->assertSame('Kazi Nayem', $rows[4]['by']);
        $this->assertSame('Goods returned', $rows[4]['detail']);
    }

    /** Older orders have their dates but no names; they say so. */
    public function test_an_order_from_before_the_log_uses_its_dates_and_says_who_is_not_recorded(): void
    {
        $this->order->forceFill([
            'status' => 'returned', 'dispatched_at' => now()->subHour(), 'stock_returned_at' => now(),
        ])->saveQuietly();

        $rows = collect($this->activity())->where('kind', 'status')->values();

        $this->assertSame(['Dispatched', 'Marked returned'], $rows->pluck('title')->all());
        $this->assertSame(['not recorded', 'not recorded'], $rows->pluck('by')->all());
    }

    public function test_only_staff_who_handle_orders_can_read_it(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->getJson("/api/admin/orders/{$this->order->id}/activity")
            ->assertForbidden();
    }
}
