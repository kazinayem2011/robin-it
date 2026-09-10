<?php

namespace Tests\Feature\Shopping;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saving things for later.
 *
 * A wishlist belongs to an account — there is no guest half — so the whole
 * surface is about one shopper not being able to read or change another's,
 * and about the list surviving the catalogue changing underneath it.
 */
class WishlistTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name = 'RTX 4070'): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'gpu'],
            ['name' => 'Graphics Cards', 'is_active' => true],
        );

        return Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'price' => 82000,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
    }

    // --- who may touch it --------------------------------------------------

    public function test_a_guest_has_no_wishlist(): void
    {
        $this->getJson('/api/wishlist')->assertUnauthorized();
        $this->postJson('/api/wishlist', ['product_id' => $this->product()->id])
            ->assertUnauthorized();
    }

    public function test_a_shopper_only_sees_their_own(): void
    {
        $mine = $this->product('Mine');
        $theirs = $this->product('Theirs');

        $me = User::factory()->create();
        $them = User::factory()->create();

        Wishlist::create(['user_id' => $me->id, 'product_id' => $mine->id]);
        Wishlist::create(['user_id' => $them->id, 'product_id' => $theirs->id]);

        $names = collect($this->actingAs($me)->getJson('/api/wishlist')->json('data'))
            ->pluck('product.name');

        $this->assertEqualsCanonicalizing(['Mine'], $names->all());
    }

    /**
     * The product id is the only thing the delete route takes, so it has to be
     * scoped by the account or one shopper could clear another's list.
     */
    public function test_a_shopper_cannot_remove_someone_elses_saved_item(): void
    {
        $product = $this->product();
        $me = User::factory()->create();
        $them = User::factory()->create();

        Wishlist::create(['user_id' => $them->id, 'product_id' => $product->id]);

        $this->actingAs($me)->deleteJson("/api/wishlist/{$product->id}")->assertOk();

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $them->id,
            'product_id' => $product->id,
        ]);
    }

    // --- keeping the list --------------------------------------------------

    public function test_saving_then_reading_it_back(): void
    {
        $product = $this->product();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/wishlist', ['product_id' => $product->id])
            ->assertOk();

        $this->assertSame(
            $product->id,
            $this->actingAs($user)->getJson('/api/wishlist')->json('data.0.product.id'),
        );
    }

    /** Tapping the heart twice is not an error, and does not save it twice. */
    public function test_saving_the_same_thing_twice_keeps_one_row(): void
    {
        $product = $this->product();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/wishlist', ['product_id' => $product->id])->assertOk();
        $this->actingAs($user)->postJson('/api/wishlist', ['product_id' => $product->id])->assertOk();

        $this->assertSame(1, Wishlist::where('user_id', $user->id)->count());
    }

    public function test_removing_takes_it_off_the_list(): void
    {
        $product = $this->product();
        $user = User::factory()->create();
        Wishlist::create(['user_id' => $user->id, 'product_id' => $product->id]);

        $this->actingAs($user)->deleteJson("/api/wishlist/{$product->id}")->assertOk();

        $this->assertSame([], $this->actingAs($user)->getJson('/api/wishlist')->json('data'));
    }

    public function test_a_product_that_does_not_exist_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/wishlist', ['product_id' => 999999])
            ->assertStatus(422);
    }

    /**
     * The list outlives the catalogue: a product the shop deletes takes its
     * saved rows with it rather than leaving the page rendering a null.
     */
    public function test_deleting_a_product_clears_it_from_saved_lists(): void
    {
        $product = $this->product();
        $user = User::factory()->create();
        Wishlist::create(['user_id' => $user->id, 'product_id' => $product->id]);

        $product->delete();

        $this->assertSame([], $this->actingAs($user)->getJson('/api/wishlist')->json('data'));
    }

    // --- suggestions -------------------------------------------------------

    /** An empty list is otherwise a page with nothing on it at all. */
    public function test_an_empty_list_still_suggests_something(): void
    {
        $this->product('Popular One');

        $suggestions = $this->actingAs(User::factory()->create())
            ->getJson('/api/wishlist/suggestions')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($suggestions);
    }

    /** What is suggested never repeats what is already saved. */
    public function test_suggestions_do_not_repeat_what_is_already_saved(): void
    {
        $saved = $this->product('Already Saved');
        $this->product('Something Else');

        $user = User::factory()->create();
        Wishlist::create(['user_id' => $user->id, 'product_id' => $saved->id]);

        $ids = collect($this->actingAs($user)->getJson('/api/wishlist/suggestions')->json('data'))
            ->pluck('id');

        $this->assertNotContains($saved->id, $ids->all());
    }
}
