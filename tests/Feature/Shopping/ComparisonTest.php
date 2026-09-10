<?php

namespace Tests\Feature\Shopping;

use App\Models\Category;
use App\Models\Comparison;
use App\Models\Product;
use App\Models\User;
use App\Services\ComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Putting two products side by side.
 *
 * Unlike the wishlist this has a guest half, keyed on the session, so the
 * awkward parts are the boundary between the two owners and the cap on how
 * many will fit across the table.
 *
 * The guest half is exercised through the model rather than a second HTTP
 * request: the test harness runs an array session driver and issues a fresh
 * session id per request, so a guest cannot be followed across two calls here
 * the way a browser follows one.
 */
class ComparisonTest extends TestCase
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

    // --- one shopper's table is their own ----------------------------------

    public function test_adding_then_reading_it_back(): void
    {
        $product = $this->product();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/compare', ['product_id' => $product->id])
            ->assertOk();

        $this->assertSame(
            $product->id,
            $this->actingAs($user)->getJson('/api/compare')->json('data.0.product.id'),
        );
    }

    public function test_a_shopper_only_sees_their_own(): void
    {
        $mine = $this->product('Mine');
        $theirs = $this->product('Theirs');

        $me = User::factory()->create();
        $them = User::factory()->create();

        Comparison::create(['user_id' => $me->id, 'product_id' => $mine->id]);
        Comparison::create(['user_id' => $them->id, 'product_id' => $theirs->id]);

        $names = collect($this->actingAs($me)->getJson('/api/compare')->json('data'))
            ->pluck('product.name');

        $this->assertEqualsCanonicalizing(['Mine'], $names->all());
    }

    public function test_a_shopper_cannot_remove_someone_elses_row(): void
    {
        $product = $this->product();
        $me = User::factory()->create();
        $them = User::factory()->create();

        Comparison::create(['user_id' => $them->id, 'product_id' => $product->id]);

        $this->actingAs($me)->deleteJson("/api/compare/{$product->id}")->assertOk();

        $this->assertDatabaseHas('comparisons', [
            'user_id' => $them->id,
            'product_id' => $product->id,
        ]);
    }

    /** A guest's table must not leak into a signed-in shopper's. */
    public function test_a_guests_row_is_not_visible_to_a_signed_in_shopper(): void
    {
        $product = $this->product();
        Comparison::create(['session_id' => 'someone-elses-session', 'product_id' => $product->id]);

        $this->assertSame(
            [],
            $this->actingAs(User::factory()->create())->getJson('/api/compare')->json('data'),
        );
    }

    // --- the cap -----------------------------------------------------------

    public function test_adding_the_same_product_twice_keeps_one_row(): void
    {
        $product = $this->product();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/compare', ['product_id' => $product->id])->assertOk();
        $this->actingAs($user)->postJson('/api/compare', ['product_id' => $product->id])->assertOk();

        $this->assertSame(1, Comparison::where('user_id', $user->id)->count());
    }

    public function test_a_fifth_product_is_refused(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 4) as $i) {
            $this->actingAs($user)
                ->postJson('/api/compare', ['product_id' => $this->product("Card {$i}")->id])
                ->assertOk();
        }

        $this->actingAs($user)
            ->postJson('/api/compare', ['product_id' => $this->product('Card 5')->id])
            ->assertStatus(422);

        $this->assertSame(4, Comparison::where('user_id', $user->id)->count());
    }

    /** A full table is not a stuck one — taking one out makes room. */
    public function test_removing_one_makes_room_for_another(): void
    {
        $user = User::factory()->create();
        $first = null;

        foreach (range(1, 4) as $i) {
            $product = $this->product("Card {$i}");
            $first ??= $product;
            $this->actingAs($user)->postJson('/api/compare', ['product_id' => $product->id])->assertOk();
        }

        $this->actingAs($user)->deleteJson("/api/compare/{$first->id}")->assertOk();

        $this->actingAs($user)
            ->postJson('/api/compare', ['product_id' => $this->product('Card 5')->id])
            ->assertOk();

        $this->assertSame(4, Comparison::where('user_id', $user->id)->count());
    }

    /** Re-adding something already on a full table is not a fifth product. */
    public function test_re_adding_something_already_on_a_full_table_is_allowed(): void
    {
        $user = User::factory()->create();
        $products = [];

        foreach (range(1, 4) as $i) {
            $products[] = $product = $this->product("Card {$i}");
            $this->actingAs($user)->postJson('/api/compare', ['product_id' => $product->id])->assertOk();
        }

        $this->actingAs($user)
            ->postJson('/api/compare', ['product_id' => $products[0]->id])
            ->assertOk();

        $this->assertSame(4, Comparison::where('user_id', $user->id)->count());
    }

    public function test_a_product_that_does_not_exist_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/compare', ['product_id' => 999999])
            ->assertStatus(422);
    }

    // --- what the table draws ----------------------------------------------

    /**
     * The screen draws a Category row. Both relations were once unloaded, so
     * every product in the table read the same fallback rather than its own
     * category.
     */
    public function test_the_payload_carries_the_category_the_table_draws(): void
    {
        $product = $this->product();
        $user = User::factory()->create();
        Comparison::create(['user_id' => $user->id, 'product_id' => $product->id]);

        $row = $this->actingAs($user)->getJson('/api/compare')->json('data.0');

        $this->assertSame('Graphics Cards', $row['product']['category']['name']);
    }

    public function test_deleting_a_product_clears_it_from_comparisons(): void
    {
        $product = $this->product();
        $user = User::factory()->create();
        Comparison::create(['user_id' => $user->id, 'product_id' => $product->id]);

        $product->delete();

        $this->assertSame([], $this->actingAs($user)->getJson('/api/compare')->json('data'));
    }

    // --- the guest half ----------------------------------------------------

    public function test_a_guests_row_is_kept_against_the_session_not_an_account(): void
    {
        $product = $this->product();

        $this->postJson('/api/compare', ['product_id' => $product->id])->assertOk();

        $row = Comparison::sole();
        $this->assertNull($row->user_id);
        $this->assertNotNull($row->session_id);
    }

    /**
     * Signing in used to strand the table, exactly as it once stranded the
     * cart: index() switches to user_id the moment there is an account, and
     * nothing carried the session rows across. Worse than the cart, because
     * login regenerates the session id — so the rows were not merely hidden,
     * they were unreachable by anyone from then on.
     */
    public function test_a_guests_table_follows_them_when_they_sign_in(): void
    {
        $product = $this->product();
        $user = User::factory()->create();

        Comparison::create(['session_id' => 'guest-session-abc', 'product_id' => $product->id]);

        app(ComparisonService::class)->mergeGuestList($user->id, 'guest-session-abc');

        $this->assertSame(
            $product->id,
            $this->actingAs($user)->getJson('/api/compare')->json('data.0.product.id'),
        );
        $this->assertDatabaseMissing('comparisons', ['session_id' => 'guest-session-abc']);
    }

    /** Something on both sides is one row afterwards, not two. */
    public function test_merging_does_not_duplicate_what_is_already_on_the_account(): void
    {
        $product = $this->product();
        $user = User::factory()->create();

        Comparison::create(['user_id' => $user->id, 'product_id' => $product->id]);
        Comparison::create(['session_id' => 'guest-session-dup', 'product_id' => $product->id]);

        app(ComparisonService::class)->mergeGuestList($user->id, 'guest-session-dup');

        $this->assertSame(1, Comparison::where('user_id', $user->id)->count());
    }

    /** The cap is the cap: signing in cannot produce a table of six. */
    public function test_merging_stops_at_the_cap(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $i) {
            Comparison::create([
                'user_id' => $user->id,
                'product_id' => $this->product("Account {$i}")->id,
            ]);
        }

        foreach (range(1, 3) as $i) {
            Comparison::create([
                'session_id' => 'guest-session-full',
                'product_id' => $this->product("Guest {$i}")->id,
            ]);
        }

        app(ComparisonService::class)->mergeGuestList($user->id, 'guest-session-full');

        $this->assertSame(
            Comparison::MAX_ITEMS,
            Comparison::where('user_id', $user->id)->count(),
        );
        $this->assertDatabaseMissing('comparisons', ['session_id' => 'guest-session-full']);
    }

    /** Merging must never be the reason a login fails. */
    public function test_merging_with_no_guest_session_is_harmless(): void
    {
        $user = User::factory()->create();

        app(ComparisonService::class)->mergeGuestList($user->id, null);

        $this->assertSame(0, Comparison::where('user_id', $user->id)->count());
    }

    /** The login path has to actually call it, with the id from before the reset. */
    public function test_signing_in_merges_the_guest_table(): void
    {
        $user = User::factory()->create(['email' => 'shopper@example.com']);

        $spy = $this->spy(ComparisonService::class);

        $this->post('/login', [
            'login' => 'shopper@example.com',
            'password' => 'password',
        ])->assertRedirect();

        $spy->shouldHaveReceived('mergeGuestList')
            ->once()
            ->withArgs(fn (int $userId, ?string $sessionId) => $userId === $user->id && $sessionId !== null);
    }
}
