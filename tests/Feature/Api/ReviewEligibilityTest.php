<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Whether the reviews endpoint can see who is asking.
 *
 * It sat in a route group without `web`, so there was no session and
 * Auth::user() came back null for a signed-in customer. The product page read
 * that as "not logged in" and told people to log in — while they were logged
 * in, looking at their own account menu.
 */
class ReviewEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Reviewable CPU',
            'slug' => 'reviewable-cpu', 'price' => 45000,
            'stock_quantity' => 0, 'is_active' => true,
        ]);

        app(StockService::class)->receive([], [['product_id' => $product->id, 'quantity' => 10]]);

        return $product->fresh();
    }

    private function buy(User $user, Product $product): Order
    {
        $this->actingAs($user)->postJson('/cart-api', [
            'product_id' => $product->id, 'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/checkout-api', [
            'name' => 'Rahim Chowdhury', 'phone' => '01712345678',
            'street_address' => 'House 45', 'city' => 'Dhaka',
        ])->assertStatus(201);

        return Order::where('user_id', $user->id)->latest()->first();
    }

    /**
     * The actual regression guard.
     *
     * The behavioural tests below cannot catch this: actingAs() sets the guard
     * in-process, so Auth::user() resolves whether or not a session exists and
     * they pass even with the middleware removed. What broke was the route's
     * middleware, so that is what is asserted.
     */
    public function test_the_reviews_endpoint_runs_with_a_session(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/products/{slug}/reviews'
                && in_array('GET', $r->methods(), true));

        $this->assertNotNull($route, 'the reviews endpoint is missing');

        $this->assertContains(
            'web',
            $route->gatherMiddleware(),
            'without `web` there is no session, so a signed-in customer reads as a guest'
        );
    }

    public function test_a_signed_in_visitor_is_recognised_as_signed_in(): void
    {
        $product = $this->product();
        $user = User::factory()->create();

        $data = $this->actingAs($user)
            ->getJson("/api/products/{$product->slug}/reviews")
            ->assertStatus(200)
            ->json('data');

        $this->assertTrue(
            $data['is_logged_in'],
            'a signed-in customer was told to log in'
        );
    }

    public function test_a_guest_is_not_reported_as_signed_in(): void
    {
        $product = $this->product();

        $data = $this->getJson("/api/products/{$product->slug}/reviews")->json('data');

        $this->assertFalse($data['is_logged_in']);
        $this->assertFalse($data['can_review']);
    }

    /** Signed in is not enough; the point of the gate is a real purchase. */
    public function test_someone_who_has_not_bought_it_cannot_review(): void
    {
        $product = $this->product();
        $user = User::factory()->create();

        $data = $this->actingAs($user)
            ->getJson("/api/products/{$product->slug}/reviews")
            ->json('data');

        $this->assertTrue($data['is_logged_in']);
        $this->assertFalse($data['can_review'], 'a non-buyer was offered the review form');
    }

    public function test_a_verified_buyer_can_review(): void
    {
        $product = $this->product();
        $user = User::factory()->create();
        $this->buy($user, $product);

        $data = $this->actingAs($user)
            ->getJson("/api/products/{$product->slug}/reviews")
            ->json('data');

        $this->assertTrue($data['is_logged_in']);
        $this->assertTrue($data['can_review'], 'a verified buyer was refused');
    }
}
