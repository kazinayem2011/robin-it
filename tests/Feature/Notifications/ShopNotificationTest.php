<?php

namespace Tests\Feature\Notifications;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\ContactMessageReceived;
use App\Notifications\OrderPlaced;
use App\Notifications\OrderStatusChanged;
use App\Notifications\ProductQuestionAsked;
use App\Notifications\StockRanLow;
use App\Services\ContactService;
use App\Services\OrderService;
use App\Services\ShopNotifier;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Telling people things.
 *
 * Two rules run through all of it. Who is told follows the same abilities the
 * admin already uses — a storekeeper hears about a shelf running low, not
 * about a customer's message — because "notify every admin" ends with the
 * accountant clearing product questions. And a notification is never allowed
 * to break the thing it is reporting on: an order that saved must stay saved
 * even if nobody could be told about it.
 */
class ShopNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => $role]);

        return $user->refresh();
    }

    private function order(?User $customer = null, string $status = 'pending'): Order
    {
        return Order::create([
            'order_number' => 'ORD-'.str_pad((string) (Order::count() + 1), 4, '0', STR_PAD_LEFT),
            'user_id' => $customer?->id,
            'session_id' => str_repeat('s', 40),
            'status' => $status,
            'subtotal' => 12500, 'shipping_fee' => 0, 'discount' => 0, 'total' => 12500,
            'payment_method' => 'COD', 'payment_status' => 'paid',
            'shipping_address' => ['name' => 'Rahim', 'phone' => '01712345678', 'city' => 'Dhaka'],
        ]);
    }

    private function product(int $stock = 0, int $reorder = 0): Product
    {
        $category = Category::firstOrCreate(['slug' => 'ram'], ['name' => 'RAM', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Corsair Vengeance',
            'slug' => 'corsair-'.uniqid(),
            'price' => 10500,
            'stock_quantity' => $stock,
            'reorder_level' => $reorder,
            'is_active' => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────── who is told

    /**
     * Every staff role covers orders — an accountant invoices them, a
     * storekeeper picks them — so the line here is staff against customer, not
     * one role against another.
     */
    public function test_an_order_tells_staff_and_no_customer(): void
    {
        Notification::fake();

        $owner = $this->staff('admin');
        $accountant = $this->staff('accountant');
        $shopper = $this->staff('customer');

        app(ShopNotifier::class)->orderPlaced($this->order());

        Notification::assertSentTo($owner, OrderPlaced::class);
        Notification::assertSentTo($accountant, OrderPlaced::class);
        Notification::assertNotSentTo($shopper, OrderPlaced::class);
    }

    public function test_a_low_shelf_tells_the_storekeeper_not_the_accountant(): void
    {
        Notification::fake();

        $keeper = $this->staff('storekeeper');
        $accountant = $this->staff('accountant');

        app(ShopNotifier::class)->stockRanLow($this->product(), null, 2);

        Notification::assertSentTo($keeper, StockRanLow::class);
        Notification::assertNotSentTo($accountant, StockRanLow::class);
    }

    public function test_a_message_tells_support_not_the_storekeeper(): void
    {
        Notification::fake();

        $support = $this->staff('support');
        $keeper = $this->staff('storekeeper');

        app(ShopNotifier::class)->contactMessage(1, 'Rakib', 'Is the showroom open Friday?');

        Notification::assertSentTo($support, ContactMessageReceived::class);
        Notification::assertNotSentTo($keeper, ContactMessageReceived::class);
    }

    /** A customer's order is the customer's business, and nobody else's. */
    public function test_a_status_change_tells_only_the_customer(): void
    {
        Notification::fake();

        $owner = $this->staff('admin');
        $customer = User::factory()->create();
        $order = $this->order($customer);

        app(ShopNotifier::class)->orderStatusChanged($order, 'shipped');

        Notification::assertSentTo($customer, OrderStatusChanged::class);
        Notification::assertNotSentTo($owner, OrderStatusChanged::class);
    }

    // ──────────────────────────────────────────────────────── what it carries

    public function test_it_carries_what_the_bell_needs_to_draw_itself(): void
    {
        $customer = User::factory()->create();
        $order = $this->order($customer);

        $payload = (new OrderStatusChanged($order, 'shipped'))->payload($customer);

        foreach (['kind', 'title', 'body', 'url', 'icon'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }

        $this->assertStringContainsString($order->order_number, $payload['title']);
        $this->assertSame('It is on its way.', $payload['body']);
    }

    /** Written down as well as pushed, or anything that happened overnight is lost. */
    public function test_it_is_stored_as_well_as_broadcast(): void
    {
        $order = $this->order();

        $this->assertSame(
            ['database', 'broadcast'],
            (new OrderPlaced($order))->via($this->staff('admin')),
        );
    }

    // ──────────────────────────────────────────────── crossing, not dwelling

    /**
     * Sent when the shelf falls to the level, and not again while it sits
     * there — a bell that rings on every movement is a bell nobody reads.
     */
    public function test_low_stock_is_announced_on_the_crossing_only(): void
    {
        Notification::fake();

        $this->staff('storekeeper');
        $product = $this->product(0, 5);
        $stock = app(StockService::class);

        $stock->record($product->fresh(), null, 10, StockMovement::PURCHASE);

        // 10 → 4 crosses the level of 5
        $stock->record($product->fresh(), null, -6, StockMovement::ADJUSTMENT, ['reason' => 'damaged']);
        Notification::assertSentTimes(StockRanLow::class, 1);

        // 4 → 3 is still low, but it was already said
        $stock->record($product->fresh(), null, -1, StockMovement::ADJUSTMENT, ['reason' => 'damaged']);
        Notification::assertSentTimes(StockRanLow::class, 1);
    }

    public function test_a_product_with_no_reorder_level_never_announces(): void
    {
        Notification::fake();

        $this->staff('storekeeper');
        $product = $this->product(0, 0);
        $stock = app(StockService::class);

        $stock->record($product->fresh(), null, 4, StockMovement::PURCHASE);
        $stock->record($product->fresh(), null, -4, StockMovement::ADJUSTMENT, ['reason' => 'damaged']);

        Notification::assertNothingSentTo([$this->staff('storekeeper')]);
    }

    // ───────────────────────────────────────────── the real trigger points

    public function test_asking_a_question_tells_support(): void
    {
        Notification::fake();

        $support = $this->staff('support');
        $product = $this->product();

        $this->postJson("/api/products/{$product->slug}/questions", [
            'name' => 'Rakib',
            'question' => 'Does this take a second stick of memory?',
        ])->assertCreated();

        Notification::assertSentTo($support, ProductQuestionAsked::class);
    }

    public function test_writing_in_tells_support(): void
    {
        Notification::fake();

        $support = $this->staff('support');

        app(ContactService::class)->record([
            'name' => 'Nusrat',
            'email' => 'nusrat@example.test',
            'subject' => 'Warranty question',
            'message' => 'How long is the warranty on the MX Master?',
        ]);

        Notification::assertSentTo($support, ContactMessageReceived::class);
    }

    public function test_changing_an_order_status_tells_its_customer(): void
    {
        Notification::fake();

        $customer = User::factory()->create();
        $order = $this->order($customer, 'pending');

        app(OrderService::class)->updateOrderStatus($order, 'processing');

        Notification::assertSentTo($customer, OrderStatusChanged::class);
    }
}
