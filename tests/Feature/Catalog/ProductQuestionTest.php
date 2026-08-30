<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Questions a shopper asks before buying, and the moderation they pass through.
 *
 * The hazard here is publication. A product page is public, so anything a
 * stranger types reaches every future visitor — which makes "not published
 * until a human says so" the only safe default, and makes the tests that pin
 * that default the ones worth having.
 */
class ProductQuestionTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $category = Category::create([
            'name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true,
        ]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test Laptop',
            'slug' => 'test-laptop',
            'price' => 100000,
            'stock_quantity' => 3,
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- asking

    public function test_a_guest_may_ask_without_an_account(): void
    {
        $product = $this->product();

        $this->postJson("/api/products/{$product->slug}/questions", [
            'name' => 'Rahim Uddin',
            'email' => 'rahim@example.com',
            'question' => 'Does this laptop take a second SSD?',
        ])->assertCreated();

        $this->assertDatabaseHas('product_questions', [
            'product_id' => $product->id,
            'question' => 'Does this laptop take a second SSD?',
            'user_id' => null,
        ]);
    }

    /**
     * The default that matters. A question goes into a queue, never onto the
     * page — otherwise the product page is an open text field pointed at every
     * future customer.
     */
    public function test_a_new_question_is_not_published(): void
    {
        $product = $this->product();

        $this->postJson("/api/products/{$product->slug}/questions", [
            'name' => 'Rahim',
            'question' => 'Is the keyboard backlit in Bengali layout?',
        ])->assertCreated();

        $this->assertDatabaseHas('product_questions', [
            'product_id' => $product->id,
            'is_published' => false,
        ]);
    }

    public function test_a_signed_in_shopper_does_not_retype_their_name(): void
    {
        $product = $this->product();
        $user = User::factory()->create(['name' => 'Kazi Nayem', 'role' => 'customer']);

        $this->actingAs($user)
            ->postJson("/api/products/{$product->slug}/questions", [
                'question' => 'Does it come with Windows pre-installed?',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('product_questions', [
            'product_id' => $product->id,
            'user_id' => $user->id,
            'name' => 'Kazi Nayem',
        ]);
    }

    public function test_a_question_too_short_to_answer_is_rejected(): void
    {
        $product = $this->product();

        $this->postJson("/api/products/{$product->slug}/questions", [
            'name' => 'Rahim',
            'question' => '?',
        ])->assertStatus(422);

        $this->assertDatabaseCount('product_questions', 0);
    }

    // ------------------------------------------------------------ reading

    public function test_only_published_questions_are_visible(): void
    {
        $product = $this->product();

        ProductQuestion::create([
            'product_id' => $product->id, 'name' => 'A',
            'question' => 'Published question about storage?',
            'answer' => 'Yes, it has an extra slot.', 'is_published' => true,
        ]);
        ProductQuestion::create([
            'product_id' => $product->id, 'name' => 'B',
            'question' => 'Hidden question awaiting moderation?', 'is_published' => false,
        ]);

        $body = $this->getJson("/api/products/{$product->slug}/questions")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $body['questions']);
        $this->assertSame('Published question about storage?', $body['questions'][0]['question']);
    }

    /**
     * A published question with no answer yet is still shown. "Asked three days
     * ago, unanswered" is information a shopper can act on; hiding it makes the
     * shop look like nobody has ever asked it anything.
     */
    public function test_a_published_question_shows_before_it_is_answered(): void
    {
        $product = $this->product();

        ProductQuestion::create([
            'product_id' => $product->id, 'name' => 'A',
            'question' => 'Is there a Bengali keyboard option?', 'is_published' => true,
        ]);

        $body = $this->getJson("/api/products/{$product->slug}/questions")->json('data');

        $this->assertCount(1, $body['questions']);
        $this->assertNull($body['questions'][0]['answer']);
        $this->assertSame(0, $body['answered']);
    }

    /**
     * A question is public and a purchase is not. Publishing "Kazi Nayem asked
     * about a 130,000 taka laptop" is more than the shopper agreed to.
     */
    public function test_only_a_first_name_is_published(): void
    {
        $product = $this->product();

        ProductQuestion::create([
            'product_id' => $product->id, 'name' => 'Kazi Nayem Ahmed',
            'question' => 'Does the warranty cover the battery fully?',
            'is_published' => true,
        ]);

        $body = $this->getJson("/api/products/{$product->slug}/questions")->json('data');

        $this->assertSame('Kazi', $body['questions'][0]['name']);
    }

    public function test_the_asker_email_is_never_exposed(): void
    {
        $product = $this->product();

        ProductQuestion::create([
            'product_id' => $product->id, 'name' => 'Rahim',
            'email' => 'private@example.com',
            'question' => 'Is this the 2024 revision of the board?',
            'is_published' => true,
        ]);

        $this->getJson("/api/products/{$product->slug}/questions")
            ->assertOk()
            ->assertDontSee('private@example.com');
    }

    // --------------------------------------------------------- moderating

    public function test_answering_publishes_in_the_same_movement(): void
    {
        $product = $this->product();
        $admin = User::factory()->create(['role' => 'admin']);

        $question = ProductQuestion::create([
            'product_id' => $product->id, 'name' => 'Rahim',
            'question' => 'Does it support 64GB of RAM?', 'is_published' => false,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/questions/{$question->id}/answer", [
                'answer' => 'Yes — two slots, 64GB maximum.',
            ])
            ->assertOk();

        $question->refresh();

        $this->assertTrue($question->is_published);
        $this->assertTrue($question->isAnswered());
        $this->assertSame($admin->id, $question->answered_by);
        $this->assertNotNull($question->answered_at);
    }

    public function test_an_answer_can_be_saved_without_publishing(): void
    {
        $product = $this->product();
        $admin = User::factory()->create(['role' => 'admin']);

        $question = ProductQuestion::create([
            'product_id' => $product->id, 'name' => 'Rahim',
            'question' => 'Can you match a competitor price?', 'is_published' => false,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/questions/{$question->id}/answer", [
                'answer' => 'Please call the showroom.',
                'is_published' => false,
            ])
            ->assertOk();

        $this->assertFalse($question->refresh()->is_published);
    }

    public function test_a_customer_cannot_answer_questions(): void
    {
        $product = $this->product();
        $customer = User::factory()->create(['role' => 'customer']);

        $question = ProductQuestion::create([
            'product_id' => $product->id, 'name' => 'Rahim',
            'question' => 'Is this genuinely in stock today?',
        ]);

        $this->actingAs($customer)
            ->patchJson("/api/admin/questions/{$question->id}/answer", [
                'answer' => 'I am not staff.',
            ])
            ->assertForbidden();

        $this->assertNull($question->refresh()->answer);
    }

    /**
     * Deleting a product must not leave its questions behind pointing at
     * nothing — they would surface as orphans in the moderation queue that
     * nobody can action or clear.
     */
    public function test_questions_are_removed_with_their_product(): void
    {
        $product = $this->product();

        ProductQuestion::create([
            'product_id' => $product->id, 'name' => 'Rahim',
            'question' => 'Anything left to ask about this?',
        ]);

        $product->delete();

        $this->assertDatabaseCount('product_questions', 0);
    }
}
