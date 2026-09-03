<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the orders screen needs in order to stop being a wall.
 *
 * The list carried eight columns and seven action icons per row, so the
 * important things could not be found among the rest. Trimming it only works
 * if the order itself can then answer everything the list stopped saying —
 * which it can only do if the server sends it.
 *
 * The money panel is the case worth guarding. Refunds were loaded as
 * `refunds:id,order_id,amount`, which was all the refund form needed and left
 * the panel rendering "Invalid Date · Refund" for every one of them.
 */
class OrderScreenPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function orderWithMoney(): Order
    {
        $category = Category::create(['name' => 'GPU', 'slug' => 'gpu', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'RTX 4070',
            'slug' => 'rtx-4070',
            'price' => 60000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $order = Order::create([
            'order_number' => 'ORD-MONEY-1',
            'user_id' => User::factory()->create()->id,
            'subtotal' => 60000,
            'shipping_fee' => 70,
            'discount' => 0,
            'total' => 60070,
            'status' => 'processing',
            'payment_method' => 'COD',
            'payment_status' => 'unpaid',
            'shipping_address' => [
                'name' => 'Rahim',
                'phone' => '01712345678',
                'street_address' => 'House 12',
                'city' => 'Dhaka',
            ],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            // Frozen on the line, so the order still reads correctly if the
            // product is renamed or withdrawn later.
            'product_name' => $product->name,
            'quantity' => 1,
            'price' => 60000,
            'total' => 60000,
        ]);

        OrderPayment::create([
            'order_id' => $order->id,
            'amount' => 20000,
            'method' => 'bkash',
            'received_by_name' => 'Counter staff',
            'received_on' => now()->toDateString(),
        ]);

        Refund::create([
            'order_id' => $order->id,
            'amount' => 5000,
            'method' => 'bkash',
            'reason' => 'damaged',
            'refunded_on' => now()->toDateString(),
        ]);

        return $order;
    }

    /**
     * A refund has to say when it happened and what it was for.
     *
     * Without these two columns the order's money log shows a row it cannot
     * date or explain, which is worse than showing nothing: it looks like a
     * bug in the shop's records rather than in the query.
     */
    public function test_a_refund_carries_its_date_and_reason(): void
    {
        $this->orderWithMoney();

        $this->actingAs($this->admin())
            ->get('/admin/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Orders')
                ->has('orders.data.0.refunds.0', fn ($refund) => $refund
                    ->where('amount', 5000)
                    ->where('reason', 'damaged')
                    ->has('created_at')
                    ->etc()
                )
            );
    }

    /** And a payment, so "৳20,000 owed" can be traced to what came in. */
    public function test_a_payment_carries_its_date_and_method(): void
    {
        $this->orderWithMoney();

        $this->actingAs($this->admin())
            ->get('/admin/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Orders')
                ->has('orders.data.0.payments.0', fn ($payment) => $payment
                    ->where('method', 'bkash')
                    ->has('created_at')
                    ->etc()
                )
            );
    }

    /**
     * The list dropped its item count and its phone column; the order shows
     * both. Neither can be shown from a payload that does not carry them.
     */
    public function test_the_order_carries_its_lines_and_the_customer(): void
    {
        $this->orderWithMoney();

        $this->actingAs($this->admin())
            ->get('/admin/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Orders')
                ->has('orders.data.0.items', 1)
                ->where('orders.data.0.shipping_address.phone', '01712345678')
            );
    }
}
