<?php

namespace Tests\Feature\Api;

use App\Constants\ApiEndpoints;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorefrontContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
    }

    private function product(int $stock = 5): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test CPU',
            'slug' => 'test-cpu-'.uniqid(),
            'price' => 25000,
            'stock_quantity' => $stock,
            'is_active' => true,
        ]);
    }

    /**
     * The browser client uses axios with baseURL '/api'. These endpoints were only
     * registered at the site root, so every cart, compare and checkout call from
     * the SPA returned 404.
     */
    public static function spaEndpointProvider(): array
    {
        return [
            'cart' => ['get', '/api/cart'],
            'compare' => ['get', '/api/compare'],
        ];
    }

    #[DataProvider('spaEndpointProvider')]
    public function test_the_endpoints_the_browser_client_calls_exist(string $verb, string $uri): void
    {
        $response = $this->actingAs(User::factory()->create())->{$verb.'Json'}($uri);

        $this->assertNotSame(404, $response->status(), "{$uri} must be reachable at the path the SPA calls.");
        $response->assertStatus(200);
    }

    public function test_adding_to_cart_works_through_the_api_prefixed_path(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertStatus(200)->assertJsonPath('error', false);

        $this->actingAs($user)->getJson('/api/cart')
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.quantity', 1);
    }

    public function test_checkout_works_through_the_api_prefixed_path(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury',
            'phone' => '01712345678',
            'street_address' => 'House 45, Road 7',
            'city' => 'Dhaka',
            // The storefront form posts `payment`, not `payment_method`.
            'payment' => 'cod',
        ])->assertStatus(201)->assertJsonPath('error', false);

        $this->assertDatabaseHas('orders', ['payment_method' => 'COD']);
    }

    /**
     * Every response shares one envelope so the client never needs a second shape.
     */
    public static function envelopeProvider(): array
    {
        return [
            'product list' => ['/api/products'],
            'flash sale' => ['/api/products/flash-sale'],
            'featured products' => ['/api/products/featured'],
            'mega menu' => ['/api/categories/mega-menu'],
            'featured categories' => ['/api/categories/featured'],
            'banners' => ['/api/banners'],
            'stores' => ['/api/stores'],
            'blogs' => ['/api/blogs'],
            'settings' => ['/api/settings'],
            'pc builder categories' => ['/api/pc-builder/categories'],
        ];
    }

    #[DataProvider('envelopeProvider')]
    public function test_responses_share_one_envelope(string $uri): void
    {
        $this->getJson($uri)
            ->assertStatus(200)
            ->assertJsonStructure(['error', 'code', 'message', 'data', 'meta'])
            ->assertJsonPath('error', false);
    }

    public function test_validation_errors_use_the_standard_envelope(): void
    {
        $this->postJson('/api/'.ApiEndpoints::ORDERS_TRACK, [])
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'code', 'message', 'data' => ['errors']])
            ->assertJsonPath('error', true)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    /**
     * Signing in used to strand the guest cart: getOrCreateCart keys on user_id
     * once authenticated, and nothing carried the session cart across.
     *
     * The HTTP layer is pinned with a spy because the test harness issues a fresh
     * session id per request; the merge semantics themselves are covered by the
     * two cases below.
     */
    public function test_signing_in_merges_the_guest_cart(): void
    {
        $user = User::factory()->create(['email' => 'shopper@example.com']);

        $spy = $this->spy(CartService::class);

        $this->post('/login', [
            'login' => 'shopper@example.com',
            'password' => 'password',
        ])->assertRedirect();

        $spy->shouldHaveReceived('mergeGuestCart')
            ->once()
            ->withArgs(fn (int $userId, ?string $sessionId) => $userId === $user->id && $sessionId !== null);
    }

    public function test_merging_combines_quantities_already_on_the_account(): void
    {
        $product = $this->product(10);
        $cartService = app(CartService::class);
        $user = User::factory()->create();

        $accountCart = $cartService->getOrCreateCart($user->id, null);
        $cartService->addItem($accountCart, $product->id, 1);

        $guestCart = $cartService->getOrCreateCart(null, 'guest-session-abc');
        $cartService->addItem($guestCart, $product->id, 2);

        $cartService->mergeGuestCart($user->id, 'guest-session-abc');

        $merged = $cartService->getCartWithItems($user->id, null);
        $this->assertCount(1, $merged->items);
        $this->assertSame(3, $merged->items->first()->quantity);
    }

    public function test_merging_never_exceeds_available_stock(): void
    {
        $product = $this->product(2);
        $cartService = app(CartService::class);
        $user = User::factory()->create();

        $accountCart = $cartService->getOrCreateCart($user->id, null);
        $cartService->addItem($accountCart, $product->id, 2);

        $guestCart = $cartService->getOrCreateCart(null, 'guest-session-xyz');
        $cartService->addItem($guestCart, $product->id, 2);

        // Signing in must not fail, and must not create an unfulfillable cart.
        $cartService->mergeGuestCart($user->id, 'guest-session-xyz');

        $merged = $cartService->getCartWithItems($user->id, null);
        $this->assertSame(2, $merged->items->first()->quantity);
    }

    /**
     * The warranty lookup returned order status and item counts for any guessed
     * order number, to anyone, unauthenticated.
     */
    public function test_warranty_check_does_not_expose_order_details(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $this->actingAs($user)->postJson('/api/cart', [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertStatus(200);

        $orderNumber = $this->actingAs($user)->postJson('/api/checkout', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45, Road 7', 'city' => 'Dhaka',
        ])->json('data.order_number');

        $response = $this->getJson('/api/'.ApiEndpoints::WARRANTY_CHECK.'?query='.$orderNumber);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.associated_order'), 'Order details must not be returned here.');
        $response->assertJsonMissing(['status' => 'pending']);
    }

    public function test_warranty_check_reports_honestly_when_nothing_is_found(): void
    {
        // It used to invent a purchase date of "8 months ago" for any input.
        $response = $this->getJson('/api/'.ApiEndpoints::WARRANTY_CHECK.'?query=UNKNOWN-SERIAL-123');

        $response->assertStatus(404)
            ->assertJsonPath('error', true)
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_warranty_check_returns_a_real_claim(): void
    {
        WarrantyClaim::create([
            'claim_number' => 'RMA-123456',
            'customer_name' => 'Rahim',
            'customer_phone' => '01712345678',
            'product_name' => 'RTX 4090',
            'serial_number' => 'SN-ABC-123',
            'issue_type' => 'No display output',
            'issue_description' => 'Card does not post after BIOS update.',
            'purchase_date' => now()->subMonths(6),
            'status' => 'diagnosing',
        ]);

        $this->getJson('/api/'.ApiEndpoints::WARRANTY_CHECK.'?query=SN-ABC-123')
            ->assertStatus(200)
            ->assertJsonPath('data.existing_claim.claim_number', 'RMA-123456')
            ->assertJsonPath('data.warranty_known', true)
            ->assertJsonPath('data.is_under_warranty', true);
    }

    public function test_a_pc_build_can_be_saved_and_reloaded_with_live_prices(): void
    {
        $product = $this->product();

        $shareCode = $this->postJson('/api/'.ApiEndpoints::PC_BUILDER_SAVE, [
            'build_name' => 'Custom Rig',
            'components' => [
                ['componentId' => 'cpu', 'product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertStatus(201)->json('data.share_code');

        // The saved total is priced from the catalogue, not from client input.
        $product->update(['price' => 30000]);

        $this->getJson('/api/pc-builder/load/'.$shareCode)
            ->assertStatus(200)
            ->assertJsonPath('data.components.0.componentId', 'cpu')
            ->assertJsonPath('data.components.0.product.raw_price', 30000)
            ->assertJsonPath('data.total_price', 30000);
    }

    public function test_saving_a_build_rejects_unknown_products(): void
    {
        $this->postJson('/api/'.ApiEndpoints::PC_BUILDER_SAVE, [
            'components' => [
                ['componentId' => 'cpu', 'product_id' => 999999, 'quantity' => 1],
            ],
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_loading_an_unknown_build_returns_a_friendly_404(): void
    {
        $this->getJson('/api/pc-builder/load/NOPE1234')
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    /**
     * Sanctum's guard checks the session before it looks for a bearer token, so a
     * cookie-authenticated SPA only works if StartSession has already run. Without
     * the `web` group a signed-in shopper's wishlist came back 401.
     *
     * actingAs() authenticates in-process and returns 200 either way, so this
     * asserts on the middleware stack rather than on a request.
     */
    public function test_session_dependent_routes_run_through_the_web_middleware_group(): void
    {
        $paths = [
            'api/wishlist',
            'api/cart',
            'api/compare',
            'api/checkout',
            'api/coupons/apply',
        ];

        foreach ($paths as $path) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $path);

            $this->assertNotNull($route, "Route {$path} should be registered.");
            $this->assertContains(
                'web',
                $route->gatherMiddleware(),
                "{$path} needs the web group for session-backed auth and CSRF."
            );
        }
    }

    public function test_every_api_route_is_rate_limited(): void
    {
        $unthrottled = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/'))
            ->reject(fn ($r) => collect($r->gatherMiddleware())
                ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))
            ->map(fn ($r) => $r->uri())
            ->values()
            ->all();

        $this->assertSame([], $unthrottled, 'Every API route must carry a throttle.');
    }

    public function test_browsing_the_cart_does_not_create_rows_for_every_visitor(): void
    {
        $this->getJson('/api/cart')->assertStatus(200)->assertJsonPath('data.items', []);

        $this->assertDatabaseCount('carts', 0);
    }
}
