<?php

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\User;
use App\Services\RefundService;
use App\Support\SmsTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The delivery charge after a return, the way other shops handle it.
 *
 * A live test returned an order and refunded all ৳4,070 of it, and the order
 * then read "৳70 owed": the return left delivery owing, and the refund form
 * gave it back anyway. Now the refund says whether delivery went back — yes
 * when the shop was at fault, no for a change of mind — and the order agrees.
 */
class DeliveryChargeOnReturnTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Mouse', 'slug' => 'mouse', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'AJAZZ Mouse', 'slug' => 'ajazz',
            'price' => 4000, 'stock_quantity' => 5, 'is_active' => true,
        ]);

        $this->order = Order::create([
            'order_number' => 'ORD-DELIVERY', 'user_id' => User::factory()->create()->id,
            'subtotal' => 4000, 'shipping_fee' => 70, 'discount' => 0, 'total' => 4070,
            'status' => 'returned', 'payment_method' => 'COD', 'payment_status' => 'paid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01712345678', 'city' => 'Dhaka'],
        ]);

        OrderItem::create([
            'order_id' => $this->order->id, 'product_id' => $product->id, 'product_name' => 'AJAZZ Mouse',
            'quantity' => 1, 'returned_quantity' => 1, 'price' => 4000, 'total' => 4000,
        ]);

        OrderPayment::create([
            'order_id' => $this->order->id, 'amount' => 4070, 'method' => 'cash', 'received_by_name' => 'Counter',
            'received_on' => now()->toDateString(),
        ]);
    }

    private function refund(float $amount, string $reason, bool $delivery): void
    {
        app(RefundService::class)->refund($this->order, [
            'amount' => $amount, 'method' => 'cash', 'reason' => $reason,
            'includes_delivery' => $delivery, 'refunded_on' => now()->toDateString(),
        ]);
    }

    public function test_a_change_of_mind_keeps_the_delivery_charge_and_owes_nothing(): void
    {
        $this->refund(4000, 'returned', false);

        $order = $this->order->fresh();
        $this->assertFalse($order->delivery_refunded);
        $this->assertSame(70.0, $order->net_value);
        $this->assertSame(0.0, $order->amount_due);
    }

    public function test_the_shops_fault_gives_the_delivery_charge_back_and_owes_nothing(): void
    {
        $this->refund(4070, 'wrong_item', true);

        $order = $this->order->fresh();
        $this->assertTrue($order->delivery_refunded);
        $this->assertSame(0.0, $order->net_value);
        $this->assertSame(0.0, $order->amount_due);
    }

    public function test_delivery_goes_back_once_and_only_where_there_was_a_charge(): void
    {
        $this->refund(2000, 'damaged', true);
        $this->refund(100, 'damaged', true);

        $this->assertSame(1, $this->order->refunds()->where('includes_delivery', true)->count());

        $this->order->forceFill(['shipping_fee' => 0])->saveQuietly();
        $this->order->refunds()->update(['includes_delivery' => false]);
        $this->refund(50, 'damaged', true);

        $this->assertSame(0, $this->order->refunds()->where('includes_delivery', true)->count());
    }

    /** The text says how the money went back — not "a few days to the bank" for cash. */
    public function test_the_refund_text_says_how_the_money_went_back(): void
    {
        $shop = 'Robins Computer';

        $this->assertSame(
            '(Robins Computer) অর্ডার ORD-DELIVERY-এর Tk 4,070 নগদ ফেরত দেওয়া হয়েছে।',
            SmsTemplates::refundIssued($this->order, 4070, $shop, 'cash'),
        );
        $this->assertStringContainsString('bKash-এ ফেরত পাঠানো হয়েছে।', SmsTemplates::refundIssued($this->order, 4070, $shop, 'bkash'));
        $this->assertStringContainsString('ব্যাংকে পাঠানো হয়েছে, আসতে কয়েক দিন লাগতে পারে।', SmsTemplates::refundIssued($this->order, 4070, $shop, 'bank'));
        $this->assertStringNotContainsString('ব্যাংক', SmsTemplates::refundIssued($this->order, 4070, $shop, 'cash'));
    }
}
