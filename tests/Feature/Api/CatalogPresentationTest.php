<?php

namespace Tests\Feature\Api;

use App\Constants\ApiEndpoints;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Services\CartService;
use App\Services\CategoryService;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogPresentationTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $slug = 'cpu'): Category
    {
        return Category::firstOrCreate(
            ['slug' => $slug],
            ['name' => strtoupper($slug), 'is_active' => true]
        );
    }

    private function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category()->id,
            'name' => 'Test Product',
            'slug' => 'test-product-'.uniqid(),
            'price' => 50000,
            'stock_quantity' => 5,
            'is_active' => true,
        ], $attrs));
    }

    /**
     * A discount_price of 0 came back from MySQL as the string "0.00", which is
     * truthy in PHP, so the card advertised ৳0 / "SAVE ৳50,000" / "-100%" while
     * the cart still charged full price.
     */
    public function test_a_zero_discount_price_is_not_treated_as_a_discount(): void
    {
        $product = $this->product(['price' => 50000, 'discount_price' => 0]);

        $card = app(ProductService::class)->formatProductCardData($product->fresh());

        $this->assertNull($card['discount'], 'A zero discount must not render as a discount.');
        $this->assertNull($card['save']);
        $this->assertNull($card['oldPrice']);
        $this->assertSame(50000.0, $card['raw_price']);
    }

    public function test_the_card_price_matches_what_the_cart_charges(): void
    {
        $user = User::factory()->create();
        $product = $this->product(['price' => 50000, 'discount_price' => 0]);

        $card = app(ProductService::class)->formatProductCardData($product->fresh());

        $cartService = app(CartService::class);
        $cart = $cartService->getOrCreateCart($user->id, null);
        $cartService->addItem($cart, $product->id, 1);

        $this->assertSame(
            $card['raw_price'],
            $cartService->calculateTotals($cart)['subtotal'],
            'The advertised price and the billed price must agree.'
        );
    }

    public function test_a_real_discount_is_still_shown(): void
    {
        $product = $this->product(['price' => 100000, 'discount_price' => 75000]);

        $card = app(ProductService::class)->formatProductCardData($product->fresh());

        $this->assertSame('-25%', $card['discount']);
        $this->assertSame(75000.0, $card['raw_price']);
        $this->assertNotNull($card['oldPrice']);
    }

    public function test_a_discount_above_list_price_is_ignored(): void
    {
        $product = $this->product(['price' => 10000, 'discount_price' => 15000]);

        $this->assertFalse($product->fresh()->hasDiscount());
        $this->assertSame(10000.0, $product->fresh()->effective_price);
    }

    public function test_an_unreviewed_product_reports_no_rating_instead_of_inventing_one(): void
    {
        $this->product(['name' => 'Brand New Item']);

        $response = $this->getJson('/api/'.ApiEndpoints::PRODUCTS_INDEX);

        $response->assertStatus(200)
            ->assertJsonPath('data.0.rating', 0)
            ->assertJsonPath('data.0.reviews', 0)
            ->assertJsonPath('data.0.sold', 0);
    }

    public function test_real_review_data_is_reported(): void
    {
        $product = $this->product();
        $user = User::factory()->create();

        foreach ([5, 4] as $rating) {
            ProductReview::create([
                'product_id' => $product->id,
                'user_id' => User::factory()->create()->id,
                'author_name' => 'Reviewer',
                'rating' => $rating,
                'comment' => 'Solid hardware, arrived quickly.',
                'is_approved' => true,
            ]);
        }

        // An unapproved review must not count toward the public average.
        ProductReview::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'author_name' => 'Pending',
            'rating' => 1,
            'comment' => 'Awaiting moderation.',
            'is_approved' => false,
        ]);

        $response = $this->getJson('/api/'.ApiEndpoints::PRODUCTS_INDEX);

        $response->assertJsonPath('data.0.reviews', 2)
            ->assertJsonPath('data.0.rating', 4.5);
    }

    public function test_units_sold_reflects_real_orders(): void
    {
        $product = $this->product();
        $order = Order::create([
            'order_number' => 'ORD-TESTSOLD1',
            'subtotal' => 100, 'shipping_fee' => 60, 'total' => 160,
            'status' => 'delivered', 'payment_method' => 'COD', 'payment_status' => 'paid',
            'shipping_address' => ['name' => 'A', 'phone' => '01712345678', 'street_address' => 'x', 'city' => 'Dhaka'],
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'price' => 100, 'quantity' => 3, 'total' => 300,
        ]);

        $this->getJson('/api/'.ApiEndpoints::PRODUCTS_INDEX)
            ->assertJsonPath('data.0.sold', 3);
    }

    /**
     * builderQuickSpecs read spec_name/spec_value; the columns are name/value, so
     * every component silently fell back to the default wattage.
     */
    public function test_pc_builder_reads_the_real_tdp_spec(): void
    {
        $product = $this->product(['name' => 'Intel Core i9']);
        $product->specifications()->create(['name' => 'TDP', 'value' => '253W']);

        $specs = app(ProductService::class)->getBuilderQuickSpecs();
        $entry = collect($specs['cpu'])->first();

        $this->assertNotNull($entry);
        $this->assertSame(253, $entry['wattage'], 'Wattage must come from the product spec, not the default.');
    }

    /**
     * The builder must find its parts under the shelves the shop actually has.
     *
     * The lookups asked for 'cpu', 'gpu' and 'ram' — what the old hundred-
     * category tree called them. The StarTech taxonomy names them
     * 'component-processor', 'component-graphics-card' and
     * 'component-ram-desktop', so every lookup found nothing and the
     * homepage's configurator rendered three empty pickers: no error, no empty
     * state, just blank.
     *
     * The existing wattage test above never caught it, because it builds its
     * own category with the slug 'cpu'. It pinned the tree that had been
     * replaced, so it stayed green while the page was blank.
     */
    public function test_the_builder_finds_parts_under_the_current_taxonomy(): void
    {
        foreach ([
            'component-processor' => 'Ryzen 7 9800X3D',
            'component-graphics-card' => 'RTX 5080',
            'component-ram-desktop' => 'Vengeance 32GB',
        ] as $slug => $name) {
            $category = Category::create([
                'name' => $name.' shelf',
                'slug' => $slug,
                'is_active' => true,
            ]);

            Product::create([
                'category_id' => $category->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'price' => 50000,
                'stock_quantity' => 3,
                'is_active' => true,
            ]);
        }

        $specs = app(ProductService::class)->getBuilderQuickSpecs();

        foreach (['cpu', 'gpu', 'ram'] as $part) {
            $this->assertNotEmpty(
                $specs[$part],
                "The configurator found no {$part}, so its picker renders blank."
            );
        }

        $this->assertSame('Ryzen 7 9800X3D', collect($specs['cpu'])->first()['name']);
        $this->assertSame('RTX 5080', collect($specs['gpu'])->first()['name']);
        $this->assertSame('Vengeance 32GB', collect($specs['ram'])->first()['name']);
    }

    public function test_product_cards_expose_wattage_for_the_builder_ui(): void
    {
        $product = $this->product();
        $product->specifications()->create(['name' => 'Power Draw', 'value' => '450 W']);

        $card = app(ProductService::class)
            ->formatProductCardData($product->fresh()->load('specifications'));

        $this->assertSame(450, $card['wattage']);
    }

    public function test_per_page_is_capped(): void
    {
        $this->product();

        // Above the cap is rejected outright rather than silently honoured.
        $this->getJson('/api/'.ApiEndpoints::PRODUCTS_INDEX.'?per_page=500000')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->getJson('/api/'.ApiEndpoints::PRODUCTS_INDEX.'?per_page=60')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 60);
    }

    public function test_featured_categories_does_not_scale_queries_with_category_count(): void
    {
        foreach (['cpu', 'ram', 'storage', 'monitors', 'motherboard', 'graphics-card'] as $slug) {
            Category::firstOrCreate(['slug' => $slug], ['name' => strtoupper($slug), 'is_active' => true]);
        }

        DB::enableQueryLog();
        app(CategoryService::class)->getFeaturedCategories();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Was ~4 queries per category (19 for six); now a fixed handful.
        $this->assertLessThanOrEqual(
            5,
            $queries,
            "getFeaturedCategories() ran {$queries} queries; it should not scale with category count."
        );
    }

    public function test_price_sorting_uses_the_discounted_price(): void
    {
        $this->product(['name' => 'Cheap After Discount', 'price' => 90000, 'discount_price' => 10000]);
        $this->product(['name' => 'Plain Mid Price', 'price' => 50000]);

        $response = $this->getJson('/api/'.ApiEndpoints::PRODUCTS_INDEX.'?sort=price_low_high');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.name', 'Cheap After Discount');
    }

    public function test_search_wildcards_do_not_match_the_whole_catalogue(): void
    {
        $this->product(['name' => 'Regular Product']);

        $response = $this->getJson('/api/'.ApiEndpoints::PRODUCTS_INDEX.'?search=%25');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'), 'A bare % must not match every product.');
    }

    public function test_product_payload_carries_computed_pricing_fields(): void
    {
        $product = $this->product(['slug' => 'detail-product', 'price' => 20000, 'discount_price' => 15000]);

        $this->getJson('/api/products/'.$product->slug)
            ->assertStatus(200)
            ->assertJsonPath('data.has_discount', true)
            ->assertJsonPath('data.effective_price', 15000)
            ->assertJsonPath('data.in_stock', true);
    }

    public function test_a_missing_product_returns_a_clean_404(): void
    {
        $this->getJson('/api/products/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('error', true)
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    /**
     * A card has to know the product is sold by option.
     *
     * has_variants was never sent, so every card treated an option product as
     * a plain one: it posted product-without-option, the server refused, and
     * the refusal — "Choose an option for … before adding it to your cart" —
     * was shown to the shopper as though something had broken. The card cannot
     * decide between adding and asking without this.
     */
    public function test_a_card_is_told_when_a_product_is_sold_by_option(): void
    {
        $plain = $this->product(['name' => 'Plain Mouse']);
        $withOptions = $this->product([
            'name' => 'Vengeance DDR5',
            'has_variants' => true,
        ]);

        $cards = collect($this->getJson('/api/'.ApiEndpoints::PRODUCTS_INDEX)->json('data'))
            ->keyBy('name');

        $this->assertFalse($cards['Plain Mouse']['has_variants']);
        $this->assertTrue($cards['Vengeance DDR5']['has_variants']);

        // Named so a card can stop offering a quantity it cannot sell.
        $this->assertArrayHasKey('min_order_quantity', $cards['Plain Mouse']);

        unset($plain, $withOptions);
    }

    /**
     * One option is not a choice, so the card is given it and adds it outright
     * rather than opening a dialog with a single answer.
     */
    public function test_a_single_option_is_offered_as_the_default(): void
    {
        $product = $this->product(['name' => 'One Option', 'has_variants' => true]);

        $variant = $product->variants()->create([
            'name' => '16GB',
            'sku' => 'ONE-16',
            'price' => 12000,
            'stock_quantity' => 4,
            'is_active' => true,
        ]);

        $card = collect($this->getJson('/api/'.ApiEndpoints::PRODUCTS_INDEX)->json('data'))
            ->firstWhere('name', 'One Option');

        $this->assertSame(1, $card['variant_count']);
        $this->assertSame($variant->id, $card['default_variant_id']);
    }

    /** The default skips an option nobody can buy. */
    public function test_the_default_option_is_one_that_is_in_stock(): void
    {
        $product = $this->product(['name' => 'Two Options', 'has_variants' => true]);

        $product->variants()->create([
            'name' => '8GB', 'sku' => 'TWO-8', 'price' => 6000,
            'stock_quantity' => 0, 'is_active' => true,
        ]);
        $stocked = $product->variants()->create([
            'name' => '32GB', 'sku' => 'TWO-32', 'price' => 24000,
            'stock_quantity' => 3, 'is_active' => true,
        ]);

        $card = collect($this->getJson('/api/'.ApiEndpoints::PRODUCTS_INDEX)->json('data'))
            ->firstWhere('name', 'Two Options');

        $this->assertSame($stocked->id, $card['default_variant_id']);
        $this->assertSame(2, $card['variant_count']);
    }
}
