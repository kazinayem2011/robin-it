<?php

namespace Tests\Feature\Storefront;

use App\Constants\ApiEndpoints;
use App\Exceptions\StorefrontException;
use App\Mail\OrderConfirmationMail;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ProductVariantService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The shop's rule: a customer never learns how many units are in stock.
 *
 * Not on a page, not in a message, and not in the JSON or page props the
 * storefront sends — anyone can read those in the browser. The stock figures
 * here are distinctive (37, 53, 41, and 94 for the options' total) so that a
 * count leaking under any name, not only the column's own, is caught: no
 * integer in a storefront response may equal one of them.
 */
class StockCountsHiddenTest extends TestCase
{
    use RefreshDatabase;

    /** Keys that are counts, or that let one be worked out. */
    private const COUNT_KEYS = ['stock_quantity', 'stockQuantity', 'totalStock', 'reorder_level', 'preorder_limit', 'available'];

    /** The stock figures seeded below. */
    private const COUNTS = [37, 53, 41, 94, 29, 17];

    private Category $shelf;

    private Product $single;

    private Product $optioned;

    private Product $preorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shelf = Category::create(['name' => 'Processor', 'slug' => 'component-processor', 'is_active' => true]);

        $this->single = Product::create([
            'category_id' => $this->shelf->id,
            'name' => 'Ryzen Quartz Edition',
            'slug' => 'ryzen-quartz-edition',
            'price' => 12000,
            'stock_quantity' => 0,
            'reorder_level' => 29,
            'is_active' => true,
        ]);
        app(StockService::class)->receive([], [['product_id' => $this->single->id, 'quantity' => 37]]);

        $this->optioned = Product::create([
            'category_id' => $this->shelf->id,
            'name' => 'Kingston Fury Beast',
            'slug' => 'kingston-fury-beast',
            'price' => 4200,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);
        app(ProductVariantService::class)->convertToVariants($this->optioned, ['Capacity'], [
            ['options' => ['Capacity' => 'Small'], 'price' => 4200, 'opening_stock' => 0],
            ['options' => ['Capacity' => 'Large'], 'price' => 8200, 'opening_stock' => 0],
        ]);
        $variants = $this->optioned->fresh('variants')->variants;
        app(StockService::class)->receive([], [
            ['product_id' => $this->optioned->id, 'product_variant_id' => $variants->firstWhere('name', 'Small')->id, 'quantity' => 53],
            ['product_id' => $this->optioned->id, 'product_variant_id' => $variants->firstWhere('name', 'Large')->id, 'quantity' => 41],
        ]);

        $this->preorder = Product::create([
            'category_id' => $this->shelf->id,
            'name' => 'Arriving Soon Board',
            'slug' => 'arriving-soon-board',
            'price' => 15000,
            'stock_quantity' => 0,
            'allow_preorder' => true,
            'preorder_limit' => 17,
            'is_active' => true,
        ]);
    }

    public function test_the_listing_and_search_send_no_count(): void
    {
        $this->assertNoCounts($this->getJson('/api/'.ApiEndpoints::PRODUCTS_INDEX));
        $this->assertNoCounts($this->getJson('/api/'.ApiEndpoints::PRODUCTS_INDEX.'?search=Kingston'));
        $this->assertNoCounts($this->getJson('/api/'.ApiEndpoints::PRODUCTS_SUGGESTIONS.'?q=Kingston'));
        $this->assertNoCounts($this->getJson('/api/'.ApiEndpoints::PRODUCTS_FLASH_SALE));
        $this->assertNoCounts($this->getJson('/api/'.ApiEndpoints::PRODUCTS_FEATURED));
    }

    public function test_a_card_says_whether_it_can_be_bought(): void
    {
        $row = collect($this->getJson('/api/'.ApiEndpoints::PRODUCTS_INDEX)->json('data'))
            ->firstWhere('slug', 'ryzen-quartz-edition');

        $this->assertTrue($row['inStock']);
    }

    public function test_the_product_page_sends_no_count(): void
    {
        foreach (['ryzen-quartz-edition', 'kingston-fury-beast', 'arriving-soon-board'] as $slug) {
            $response = $this->getJson('/api/products/'.$slug)->assertStatus(200);
            $this->assertNoCounts($response);
        }

        // The options still say which can be bought.
        $options = $this->getJson('/api/products/kingston-fury-beast')->json('data.active_variants');
        $this->assertCount(2, $options);
        $this->assertTrue($options[0]['in_stock']);

        // And a pre-order product is still marked as one.
        $this->assertTrue($this->getJson('/api/products/arriving-soon-board')->json('data.is_preorder'));
    }

    public function test_the_pc_builder_sends_no_count(): void
    {
        $response = $this->getJson('/api/pc-builder/components/component-processor')->assertStatus(200);

        $this->assertNotEmpty($response->json('data'));
        $this->assertNoCounts($response);
    }

    public function test_the_cart_sends_no_count(): void
    {
        $user = User::factory()->create();
        $option = $this->optioned->fresh('variants')->variants->firstWhere('name', 'Small');

        $this->assertNoCounts($this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $this->single->id, 'quantity' => 2,
        ])->assertStatus(200));

        $added = $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $this->optioned->id, 'product_variant_id' => $option->id, 'quantity' => 1,
        ])->assertStatus(200);
        $this->assertNoCounts($added);

        $this->assertNoCounts($this->actingAs($user)->patchJson(
            '/api/'.str_replace('{itemId}', $added->json('data.id'), ApiEndpoints::CART_ITEM),
            ['quantity' => 2]
        )->assertStatus(200));

        $this->assertNoCounts($this->actingAs($user)->getJson('/api/'.ApiEndpoints::CART)->assertStatus(200));
        $this->assertNoCounts($this->actingAs($user)->getJson('/api/'.ApiEndpoints::CART_SUGGESTIONS)->assertStatus(200));
    }

    public function test_compare_sends_no_count(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::COMPARE, ['product_id' => $this->single->id])->assertStatus(200);
        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::COMPARE, ['product_id' => $this->optioned->id])->assertStatus(200);

        $response = $this->actingAs($user)->getJson('/api/'.ApiEndpoints::COMPARE)->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
        $this->assertNoCounts($response);
    }

    public function test_the_wishlist_sends_no_count(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::WISHLIST, ['product_id' => $this->single->id])->assertStatus(200);
        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::WISHLIST, ['product_id' => $this->preorder->id])->assertStatus(200);

        $this->assertNoCounts($this->actingAs($user)->getJson('/api/'.ApiEndpoints::WISHLIST)->assertStatus(200));
        $this->assertNoCounts($this->actingAs($user)->getJson('/api/'.ApiEndpoints::WISHLIST_SUGGESTIONS)->assertStatus(200));

        // The account's own wishlist page, whose props reach the browser too.
        $page = $this->actingAs($user)->get(ApiEndpoints::DASHBOARD_WISHLIST)->assertStatus(200);
        $this->assertNoCountsIn($page->viewData('page')['props']);
    }

    /*
     * More than is on the shelf of an ordinary product: taken, and owed. The
     * customer is told nothing — no refusal, no warning in the cart.
     */
    public function test_ordering_beyond_the_shelf_says_nothing(): void
    {
        $user = User::factory()->create();
        $few = Product::create([
            'category_id' => $this->shelf->id,
            'name' => 'Short Shelf Cooler',
            'slug' => 'short-shelf-cooler',
            'price' => 3000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);
        app(StockService::class)->receive([], [['product_id' => $few->id, 'quantity' => 3]]);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $few->id, 'quantity' => 5,
        ])->assertStatus(200);

        $cart = $this->actingAs($user)->getJson('/api/'.ApiEndpoints::CART)->assertStatus(200);
        $cart->assertJsonPath('data.issues', []);
        $this->assertNoCounts($cart);

        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CHECKOUT, [
            'name' => 'Rahim Chowdhury',
            'phone' => '01712345678',
            'street_address' => 'House 45, Road 7, Gulshan 2',
            'city' => 'Dhaka',
        ])->assertStatus(201);

        // Nor does the confirmation page single the line out.
        $order = Order::latest('id')->first();
        $this->actingAs($user)
            ->get('/order/success?order='.$order->order_number)
            ->assertInertia(fn ($page) => $page->where('preorderItems', []));

        // Nor, afterwards, their orders, tracking, email or invoice — and none
        // of them carries what the shop paid for the goods.
        $this->assertTrue($order->items()->first()->waitingForStock(), 'the shop sees it waiting');

        $orders = json_encode($this->actingAs($user)->get('/dashboard/orders')->viewData('page')['props']['orders']);
        $this->assertStringNotContainsString('waiting_for_stock', $orders);
        $this->assertStringNotContainsString('was_preordered', $orders);
        $this->assertStringNotContainsString('unit_cost', $orders);
        $this->assertStringContainsString('"is_preorder":false', $orders);

        $tracked = app(OrderService::class)->trackOrder($order->order_number, '01712345678');
        $this->assertFalse($tracked['items'][0]['is_preorder']);

        $email = (new OrderConfirmationMail($order->fresh()))->render();
        $this->assertStringNotContainsStringIgnoringCase('waiting for stock', $email);
        $this->assertStringNotContainsStringIgnoringCase('next delivery', $email);

        $invoice = $this->actingAs($user)->get("/orders/{$order->id}/invoice")->getContent();
        $this->assertStringNotContainsStringIgnoringCase('waiting for stock', $invoice);
    }

    /* A genuine pre-order, which the customer chose knowingly, is still marked. */
    public function test_a_real_pre_order_is_still_marked_for_the_customer(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $this->preorder->id, 'quantity' => 1,
        ])->assertStatus(200);
        $this->actingAs($user)->postJson('/api/'.ApiEndpoints::CHECKOUT, [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45, Road 7, Gulshan 2', 'city' => 'Dhaka',
        ])->assertStatus(201);
        $order = Order::latest('id')->first();

        $orders = json_encode($this->actingAs($user)->get('/dashboard/orders')->viewData('page')['props']['orders']);
        $this->assertStringContainsString('"is_preorder":true', $orders);

        $email = (new OrderConfirmationMail($order->fresh()))->render();
        $this->assertStringContainsString('Pre-order', $email);
    }

    /* Refused — a pre-order past its limit — and still no number. */
    public function test_a_refusal_names_no_number(): void
    {
        $limited = Product::create([
            'category_id' => $this->shelf->id,
            'name' => 'Limited Board',
            'slug' => 'limited-board',
            'price' => 9000,
            'stock_quantity' => 0,
            'allow_preorder' => true,
            'preorder_limit' => 7,
            'is_active' => true,
        ]);
        app(StockService::class)->receive([], [['product_id' => $limited->id, 'quantity' => 6]]);

        $response = $this->actingAs(User::factory()->create())->postJson('/api/'.ApiEndpoints::CART, [
            'product_id' => $limited->id, 'quantity' => 14,
        ])->assertStatus(422)->assertJsonPath('code', 'OUT_OF_STOCK');

        $this->assertDoesNotMatchRegularExpression('/\d/', $response->json('message'));
        $this->assertNoCounts($response);
    }

    public function test_the_out_of_stock_message_names_no_number(): void
    {
        $e = StorefrontException::outOfStock('Quiet Fan', 37);

        $this->assertDoesNotMatchRegularExpression('/\d/', $e->getMessage());
        $this->assertStringContainsString("can't supply that many", $e->getMessage());
        $this->assertArrayNotHasKey('available', $e->context());

        $limit = StorefrontException::preorderLimit('Quiet Fan', 37);

        $this->assertDoesNotMatchRegularExpression('/\d/', $limit->getMessage());
        $this->assertArrayNotHasKey('available', $limit->context());
    }

    private function assertNoCounts(TestResponse $response): void
    {
        $this->assertNoCountsIn($response->json());
    }

    private function assertNoCountsIn(mixed $data, string $path = ''): void
    {
        if (! is_array($data)) {
            if (is_int($data)) {
                $this->assertNotContains($data, self::COUNTS, "a stock figure was sent at {$path}");
            }

            return;
        }

        foreach ($data as $key => $value) {
            $this->assertNotContains($key, self::COUNT_KEYS, "the storefront was sent {$path}.{$key}");
            $this->assertNoCountsIn($value, "{$path}.{$key}");
        }
    }
}
