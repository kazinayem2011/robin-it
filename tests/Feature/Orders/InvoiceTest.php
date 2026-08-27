<?php

namespace Tests\Feature\Orders;

use App\Http\Controllers\InvoiceController;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductVariantService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The printable invoice.
 *
 * Authorisation carries the weight here: an invoice has the customer's name,
 * home address and phone number on it, so guessing an order id must not be
 * enough to read one.
 */
class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    /** Session::setId only accepts 40 alphanumeric characters. */
    private const SESSION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function product(int $stock = 10, float $price = 45000): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Ryzen 7 7800X3D',
            'slug' => 'ryzen-'.uniqid(), 'price' => $price,
            'stock_quantity' => 0, 'is_active' => true,
        ]);

        if ($stock > 0) {
            app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => $stock]]);
        }

        return $product->fresh();
    }

    private function placeOrder(?User $user, Product $product, int $qty = 2): Order
    {
        $request = $user ? $this->actingAs($user) : $this;

        $request->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => $qty])
            ->assertStatus(200);

        ($user ? $this->actingAs($user) : $this)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45, Road 7', 'city' => 'Dhaka',
        ])->assertStatus(201);

        return Order::latest()->first();
    }

    public function test_a_customer_can_print_their_own_invoice(): void
    {
        $user = User::factory()->create();
        $order = $this->placeOrder($user, $this->product());

        $response = $this->actingAs($user)->get("/orders/{$order->id}/invoice");

        $response->assertStatus(200);
        $response->assertSee($order->order_number);
        $response->assertSee('Rahim Chowdhury');
        $response->assertSee('Ryzen 7 7800X3D');
    }

    /** The failure that would matter: reading somebody else's. */
    public function test_a_customer_cannot_read_another_persons_invoice(): void
    {
        $owner = User::factory()->create();
        $order = $this->placeOrder($owner, $this->product());

        $this->actingAs(User::factory()->create())
            ->get("/orders/{$order->id}/invoice")
            ->assertStatus(403);
    }

    public function test_a_stranger_cannot_read_an_invoice_by_guessing_the_id(): void
    {
        $order = $this->placeOrder(User::factory()->create(), $this->product());

        // placeOrder authenticated as the owner and that persists across
        // requests, so the "stranger" has to be made a stranger explicitly.
        auth()->logout();

        $this->get("/orders/{$order->id}/invoice")->assertStatus(403);
    }

    /** A guest order, tied to the session that placed it. */
    private function guestOrder(string $sessionId): Order
    {
        $product = $this->product();

        $order = Order::create([
            'order_number' => 'ORD-GUEST'.substr(md5($sessionId), 0, 6),
            'user_id' => null,
            'session_id' => $sessionId,
            'subtotal' => 45000, 'shipping_fee' => 60, 'discount' => 0,
            'total' => 45060, 'status' => 'pending',
            'payment_method' => 'COD', 'payment_status' => 'unpaid',
            'shipping_address' => [
                'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
                'street_address' => 'House 45', 'city' => 'Dhaka',
            ],
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => 45000, 'quantity' => 1, 'total' => 45000,
        ]);

        return $order->fresh('items');
    }

    public function test_an_admin_can_print_any_invoice(): void
    {
        $order = $this->placeOrder(User::factory()->create(), $this->product());

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get("/orders/{$order->id}/invoice")
            ->assertStatus(200)
            ->assertSee($order->order_number);
    }

    /**
     * Someone who checked out as a guest still needs their own receipt.
     *
     * Driven through the controller rather than over HTTP: the test harness
     * regenerates the session id on every request, so a session-scoped rule
     * cannot be exercised by two successive calls. In the browser the cookie
     * keeps it stable, which is the case being checked here.
     */
    public function test_a_guest_can_print_the_order_they_just_placed(): void
    {
        $order = $this->guestOrder(self::SESSION_ID);

        $request = Request::create("/orders/{$order->id}/invoice");
        $session = app('session.store');
        $session->setId(self::SESSION_ID);
        $session->start();
        $request->setLaravelSession($session);

        $response = app(InvoiceController::class)->show($request, $order->id);

        $this->assertStringContainsString($order->order_number, $response->render());
    }

    public function test_a_different_session_cannot_read_a_guest_invoice(): void
    {
        // An order placed in somebody else's session.
        $order = $this->guestOrder(str_repeat('b', 40));

        $this->startSession();

        $this->get("/orders/{$order->id}/invoice")->assertStatus(403);
    }

    public function test_a_missing_order_is_a_404_not_a_500(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/orders/999999/invoice')
            ->assertStatus(404);
    }

    public function test_the_invoice_names_the_option_that_was_bought(): void
    {
        $product = $this->product(0);
        // Options first: a product holding stock can no longer be restructured.
        app(ProductVariantService::class)->convertToVariants($product, ['Edition'], [
            ['options' => ['Edition' => 'Boxed'], 'opening_stock' => 0],
        ]);

        $variant = $product->fresh('variants')->variants->first();
        app(StockService::class)->receive([], [[
            'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 6,
        ]]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        $this->actingAs($user)
            ->get('/orders/'.Order::latest()->first()->id.'/invoice')
            ->assertStatus(200)
            ->assertSee('Boxed');
    }

    public function test_the_totals_add_up_on_the_page(): void
    {
        $user = User::factory()->create();
        $order = $this->placeOrder($user, $this->product(10, 45000), 2);

        $response = $this->actingAs($user)->get("/orders/{$order->id}/invoice");

        $response->assertSee('৳'.number_format($order->subtotal, 2), false);
        $response->assertSee('৳'.number_format($order->total, 2), false);
    }

    public function test_a_cash_on_delivery_order_says_what_to_have_ready(): void
    {
        $user = User::factory()->create();
        $order = $this->placeOrder($user, $this->product());

        $this->actingAs($user)
            ->get("/orders/{$order->id}/invoice")
            ->assertStatus(200)
            ->assertSee('ready for the delivery rider', false);
    }

    /** An invoice carries a home address; it must never be indexed. */
    public function test_the_invoice_is_not_indexable(): void
    {
        $user = User::factory()->create();
        $order = $this->placeOrder($user, $this->product());

        $this->actingAs($user)
            ->get("/orders/{$order->id}/invoice")
            ->assertSee('noindex', false);
    }
}
