<?php

namespace Tests\Feature\Stock;

use App\Jobs\NotifyBackInStock;
use App\Mail\BackInStockMail;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockNotification;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ProductVariantService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Telling people when a sold-out item comes back.
 *
 * The shelf crossing zero is the trigger; everything here is about that moment
 * being detected exactly once, and never announced when it did not happen.
 */
class BackInStockTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $stock = 0): Product
    {
        $category = Category::firstOrCreate(['slug' => 'gpu'], ['name' => 'GPU', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'RTX 4090',
            'slug' => 'rtx-4090-'.uniqid(),
            'price' => 250000,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        if ($stock > 0) {
            app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => $stock]]);
        }

        return $product->fresh();
    }

    private function subscribe(Product $product, string $email = 'shopper@example.com', ?int $variantId = null)
    {
        return $this->postJson('/api/stock-notifications', array_filter([
            'product_id' => $product->id,
            'product_variant_id' => $variantId,
            'email' => $email,
        ]));
    }

    public function test_a_shopper_can_ask_to_be_told(): void
    {
        $product = $this->product(0);

        $this->subscribe($product)->assertStatus(201);

        $this->assertDatabaseHas('stock_notifications', [
            'product_id' => $product->id,
            'email' => 'shopper@example.com',
            'notified_at' => null,
        ]);
    }

    public function test_asking_twice_is_reassuring_rather_than_an_error(): void
    {
        $product = $this->product(0);

        $this->subscribe($product)->assertStatus(201);
        $this->subscribe($product)->assertStatus(201);

        $this->assertSame(1, StockNotification::count());
    }

    public function test_the_email_is_normalised_so_case_does_not_duplicate(): void
    {
        $product = $this->product(0);

        $this->subscribe($product, 'Shopper@Example.com')->assertStatus(201);
        $this->subscribe($product, 'shopper@example.com')->assertStatus(201);

        $this->assertSame(1, StockNotification::count());
    }

    public function test_asking_about_something_in_stock_is_refused(): void
    {
        $product = $this->product(5);

        $this->subscribe($product)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Good news — this is in stock right now.']);

        $this->assertSame(0, StockNotification::count());
    }

    /** The whole point: a delivery should reach the people waiting. */
    public function test_receiving_stock_queues_the_notification(): void
    {
        Bus::fake();
        $product = $this->product(0);
        $this->subscribe($product);

        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 4]]);

        Bus::assertDispatched(
            NotifyBackInStock::class,
            fn ($job) => $job->productId === $product->id && $job->variantId === null
        );
    }

    public function test_the_waiting_list_is_emailed_and_then_cleared(): void
    {
        Mail::fake();
        $product = $this->product(0);
        $this->subscribe($product, 'one@example.com');
        $this->subscribe($product, 'two@example.com');

        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 3]]);

        Mail::assertSent(BackInStockMail::class, 2);
        $this->assertSame(0, StockNotification::pending()->count(), 'someone would be emailed twice');
    }

    /** Restocking something that was never out must not email anyone. */
    public function test_a_delivery_onto_an_already_stocked_shelf_notifies_nobody(): void
    {
        // Stocked first: creating it from zero is itself a crossing, and
        // faking before that would catch the setup rather than the case.
        $product = $this->product(5);

        Bus::fake();
        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 5]]);

        Bus::assertNotDispatched(NotifyBackInStock::class);
    }

    /**
     * The stock can go again between the delivery and the job running. Saying
     * it is back when it is not is worse than saying nothing.
     */
    public function test_nothing_is_sent_if_it_sold_out_again_first(): void
    {
        Mail::fake();
        $product = $this->product(0);
        $this->subscribe($product);

        // Hold the job back so the gap between delivery and delivery-of-mail
        // can be recreated; on the sync queue it would otherwise run instantly.
        Bus::fake();
        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 2]]);

        // Someone bought both before the queue got to it.
        app(StockService::class)->adjust($product->fresh(), null, -2, 'lost');

        (new NotifyBackInStock($product->id))->handle();

        Mail::assertNothingSent();
        $this->assertSame(1, StockNotification::pending()->count(), 'the request was consumed for nothing');
    }

    public function test_a_cancelled_order_putting_stock_back_also_notifies(): void
    {
        Bus::fake();
        $product = $this->product(2);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/cart', ['product_id' => $product->id, 'quantity' => 2]);
        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        $this->assertSame(0, $product->fresh()->stock_quantity);

        Bus::fake();
        $order = Order::where('user_id', $user->id)->latest()->first();
        app(OrderService::class)->updateOrderStatus($order, 'cancelled');

        Bus::assertDispatched(NotifyBackInStock::class);
    }

    public function test_a_variant_product_requires_choosing_which_option(): void
    {
        $product = $this->product(0);
        // Options first: a product holding stock can no longer be restructured.
        app(ProductVariantService::class)->convertToVariants($product, ['Edition'], [
            ['options' => ['Edition' => 'OC'], 'opening_stock' => 0],
            ['options' => ['Edition' => 'Standard'], 'opening_stock' => 0],
        ]);
        app(StockService::class)->receive([], [[
            'product_id' => $product->id,
            'product_variant_id' => $product->fresh('variants')->variants->firstWhere('name', 'OC')->id,
            'quantity' => 4,
        ]]);

        $this->subscribe($product->fresh())
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Choose which option you are waiting for.']);
    }

    /** One option coming back says nothing about another. */
    public function test_only_the_waited_for_option_triggers_an_email(): void
    {
        Mail::fake();
        $product = $this->product(0);
        // Options first: a product holding stock can no longer be restructured.
        app(ProductVariantService::class)->convertToVariants($product, ['Edition'], [
            ['options' => ['Edition' => 'OC'], 'opening_stock' => 0],
            ['options' => ['Edition' => 'Standard'], 'opening_stock' => 0],
        ]);
        app(StockService::class)->receive([], [[
            'product_id' => $product->id,
            'product_variant_id' => $product->fresh('variants')->variants->firstWhere('name', 'OC')->id,
            'quantity' => 4,
        ]]);

        $product = $product->fresh('variants');
        $soldOut = $product->variants->firstWhere('name', 'Standard');
        $inStock = $product->variants->firstWhere('name', 'OC');

        $this->subscribe($product, 'waiting@example.com', $soldOut->id)->assertStatus(201);

        // More of the option that was never out.
        app(StockService::class)->receive([], [[
            'product_id' => $product->id,
            'product_variant_id' => $inStock->id,
            'quantity' => 5,
        ]]);
        Mail::assertNothingSent();

        // Now the one actually being waited for.
        app(StockService::class)->receive([], [[
            'product_id' => $product->id,
            'product_variant_id' => $soldOut->id,
            'quantity' => 2,
        ]]);
        Mail::assertSent(BackInStockMail::class, 1);
    }

    public function test_the_request_endpoint_validates_the_address(): void
    {
        $product = $this->product(0);

        $this->postJson('/api/stock-notifications', [
            'product_id' => $product->id,
            'email' => 'not-an-email',
        ])->assertStatus(422);
    }

    public function test_the_page_can_show_how_many_are_waiting(): void
    {
        $product = $this->product(0);
        $this->subscribe($product, 'one@example.com');
        $this->subscribe($product, 'two@example.com');

        $response = $this->getJson("/api/stock-notifications/count?product_id={$product->id}");

        $this->assertSame(2, $response->json('data.waiting'));
    }
}
