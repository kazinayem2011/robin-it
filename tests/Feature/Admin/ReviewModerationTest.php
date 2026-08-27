<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Reviews publish on submission and nothing let an admin take one down, despite
 * the is_approved column existing. This covers the moderation screen.
 */
class ReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
    }

    private function product(): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test CPU',
            'slug' => 'test-cpu-'.uniqid(),
            'price' => 5000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
    }

    private function review(Product $product, bool $approved = true): ProductReview
    {
        return ProductReview::create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'author_name' => 'Rahim',
            'rating' => 5,
            'comment' => 'Excellent hardware, fast delivery.',
            'is_verified_buyer' => true,
            'is_approved' => $approved,
        ]);
    }

    public function test_an_admin_can_open_the_moderation_screen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->review($this->product());

        $this->actingAs($admin)->get('/admin/reviews')->assertStatus(200);
    }

    public function test_a_customer_cannot_open_it(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)->get('/admin/reviews')->assertRedirect();
    }

    public function test_an_admin_can_hide_a_review(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $review = $this->review($this->product());

        $this->actingAs($admin)
            ->patchJson("/api/admin/reviews/{$review->id}/status", ['is_approved' => false])
            ->assertStatus(200)
            ->assertJsonPath('error', false);

        $this->assertFalse($review->fresh()->is_approved);
    }

    public function test_a_hidden_review_disappears_from_the_storefront(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product();
        $review = $this->review($product);

        $this->getJson("/api/products/{$product->slug}/reviews")
            ->assertJsonPath('data.total_reviews', 1);

        $this->actingAs($admin)
            ->patchJson("/api/admin/reviews/{$review->id}/status", ['is_approved' => false])
            ->assertStatus(200);

        $this->getJson("/api/products/{$product->slug}/reviews")
            ->assertJsonPath('data.total_reviews', 0);
    }

    public function test_hiding_a_review_removes_it_from_the_product_rating(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product();

        $five = $this->review($product);
        ProductReview::create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'author_name' => 'Karim',
            'rating' => 1,
            'comment' => 'Spam review that should come down.',
            'is_approved' => true,
        ]);

        $this->getJson('/api/products')->assertJsonPath('data.0.rating', 3);

        $this->actingAs($admin)
            ->patchJson("/api/admin/reviews/{$five->id}/status", ['is_approved' => false])
            ->assertStatus(200);

        $this->getJson('/api/products')
            ->assertJsonPath('data.0.rating', 1)
            ->assertJsonPath('data.0.reviews', 1);
    }

    public function test_an_admin_can_republish_a_hidden_review(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $review = $this->review($this->product(), approved: false);

        $this->actingAs($admin)
            ->patchJson("/api/admin/reviews/{$review->id}/status", ['is_approved' => true])
            ->assertStatus(200);

        $this->assertTrue($review->fresh()->is_approved);
    }

    public function test_an_admin_can_delete_a_review(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $review = $this->review($this->product());

        $this->actingAs($admin)
            ->deleteJson("/api/admin/reviews/{$review->id}")
            ->assertStatus(200);

        $this->assertDatabaseCount('product_reviews', 0);
    }

    public function test_a_customer_cannot_moderate_reviews(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $review = $this->review($this->product());

        $response = $this->actingAs($customer)
            ->patchJson("/api/admin/reviews/{$review->id}/status", ['is_approved' => false]);

        $this->assertContains($response->status(), [302, 403]);
        $this->assertTrue($review->fresh()->is_approved, 'A customer must not be able to hide reviews.');
    }

    public function test_the_screen_can_be_filtered_by_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = $this->product();
        $this->review($product, approved: true);
        $this->review($product, approved: false);

        $this->actingAs($admin)->get('/admin/reviews?status=hidden')->assertStatus(200);
        $this->actingAs($admin)->get('/admin/reviews?status=published')->assertStatus(200);
    }
}
