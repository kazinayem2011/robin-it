<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The moderation screen itself, as opposed to the endpoints behind it.
 *
 * Worth its own file because the failure it guards against already happened:
 * the controller, the routes and the API were all written, tested and passing
 * while the Inertia page did not exist, so every one of those tests was green
 * and the screen was a 500. Rendering is a separate claim from responding.
 */
class ProductQuestionModerationTest extends TestCase
{
    use RefreshDatabase;

    private function question(array $attributes = []): ProductQuestion
    {
        $category = Category::create([
            'name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'MSI Cyborg 15',
            'slug' => 'msi-cyborg-15',
            'price' => 132000,
            'stock_quantity' => 2,
            'is_active' => true,
        ]);

        return ProductQuestion::create(array_merge([
            'product_id' => $product->id,
            'name' => 'Rahim Uddin',
            'question' => 'Does this laptop take a second SSD?',
        ], $attributes));
    }

    public function test_the_moderation_page_renders(): void
    {
        $this->question();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/questions')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Admin/ProductQuestions')
                    ->has('questions.data', 1)
                    ->has('counts')
            );
    }

    /**
     * The page reads `answered_by_name` and `product.name`. Passing the model
     * through untouched would send the asker's email to the browser and leave
     * those two fields undefined.
     */
    public function test_the_page_gets_the_fields_it_reads_and_not_the_email(): void
    {
        $this->question(['email' => 'private@example.com']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/questions')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('questions.data.0', fn (AssertableInertia $q) => $q
                        ->where('name', 'Rahim Uddin')
                        ->where('question', 'Does this laptop take a second SSD?')
                        ->has('product.name')
                        ->has('product.slug')
                        ->has('answered_by_name')
                        ->has('id')
                        ->has('answer')
                        ->has('is_published')
                        ->has('created_at')
                        ->missing('email')
                    )
            );
    }

    /**
     * The default landing filter. An unanswered question is a shopper still
     * deciding, so the queue opens on those rather than on everything.
     */
    public function test_it_opens_on_the_unanswered_queue(): void
    {
        $this->question(['answer' => 'Yes, two slots.', 'is_published' => true]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/questions')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('filters.filter', 'unanswered')
                    ->has('questions.data', 0)
            );
    }

    public function test_the_all_filter_shows_answered_questions_too(): void
    {
        $this->question(['answer' => 'Yes, two slots.', 'is_published' => true]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/questions?filter=all')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('questions.data', 1));
    }

    /**
     * Redirected, not 403'd. AdminMiddleware sends a browser somewhere it can
     * use rather than showing it an error page — the same contract
     * AdminAuthorizationTest pins for every other admin screen.
     */
    public function test_a_customer_cannot_open_the_queue(): void
    {
        $this->question();

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->get('/admin/questions')
            ->assertRedirect();
    }

    public function test_a_guest_cannot_open_the_queue(): void
    {
        $this->question();

        $this->get('/admin/questions')->assertRedirect();
    }
}
