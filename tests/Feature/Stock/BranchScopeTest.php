<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\StockService;
use App\Support\BranchScope;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A storekeeper sees the branch they work in.
 *
 * Staff accounts have carried a branch since roles were introduced and nothing
 * read it, so somebody assigned to one showroom saw every branch's shelves and
 * could adjust, receive into and transfer out of any of them.
 */
class BranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private Store $mine;

    private Store $theirs;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Roles::forget();

        $this->mine = $this->store('Uttara', 'Dhaka', 1);
        $this->theirs = $this->store('Agrabad', 'Chattogram', 2);

        $this->product = Product::create([
            'category_id' => Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true])->id,
            'name' => 'Test CPU', 'slug' => 'test-cpu-branch', 'price' => 5000,
            'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    private function store(string $name, string $city, int $order): Store
    {
        return Store::create([
            'name' => $name,
            'city' => $city,
            'address' => 'Test address',
            'phone' => '01711000000',
            'is_active' => true,
            'holds_stock' => true,
            'fulfils_online' => true,
            'sort_order' => $order,
        ]);
    }

    private function keeper(?Store $store): User
    {
        return User::factory()->create([
            'role' => Roles::STOREKEEPER,
            'is_active' => true,
            'store_id' => $store?->id,
        ]);
    }

    private function stockAt(Store $store, int $quantity): void
    {
        app(StockService::class)->receive(
            ['store_id' => $store->id, 'received_on' => now()->toDateString()],
            [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => 100]]
        );
    }

    // --- the rule itself ------------------------------------------------

    public function test_a_keeper_with_a_branch_is_confined_to_it(): void
    {
        $this->assertSame($this->mine->id, BranchScope::for($this->keeper($this->mine)));
    }

    public function test_a_keeper_with_no_branch_sees_the_whole_shop(): void
    {
        $this->assertNull(BranchScope::for($this->keeper(null)));
    }

    /**
     * Whoever manages branches is not confined by one.
     *
     * An owner who works out of a single shop may reasonably be assigned to
     * it; confining them would hide the other branches from the only person
     * who can undo it.
     */
    public function test_an_owner_assigned_to_a_branch_is_not_confined(): void
    {
        $owner = User::factory()->create([
            'role' => Roles::OWNER, 'is_active' => true, 'store_id' => $this->mine->id,
        ]);

        $this->assertNull(BranchScope::for($owner));
    }

    public function test_a_confined_keeper_may_only_act_on_their_own_branch(): void
    {
        $keeper = $this->keeper($this->mine);

        $this->assertTrue(BranchScope::allows($keeper, $this->mine->id));
        $this->assertFalse(BranchScope::allows($keeper, $this->theirs->id));
        // "Wherever the shop keeps it" is not a thing a confined person may say.
        $this->assertFalse(BranchScope::allows($keeper, null));
    }

    // --- what they are shown --------------------------------------------

    public function test_the_stock_page_names_the_branch_and_offers_no_other(): void
    {
        $props = $this->actingAs($this->keeper($this->mine))
            ->get('/admin/stock')
            ->assertStatus(200)
            ->viewData('page')['props'];

        $this->assertSame('Uttara', $props['branch']);
        $this->assertCount(1, $props['stores']);
        $this->assertSame($this->mine->id, $props['stores'][0]['id']);
        $this->assertSame($this->mine->id, $props['filters']['store']);
    }

    public function test_asking_for_another_branch_shows_your_own(): void
    {
        $props = $this->actingAs($this->keeper($this->mine))
            ->get('/admin/stock?store='.$this->theirs->id)
            ->viewData('page')['props'];

        // Narrowed, not refused: a branch is a filter here.
        $this->assertSame($this->mine->id, $props['filters']['store']);
    }

    public function test_an_unconfined_person_still_sees_every_branch(): void
    {
        $props = $this->actingAs($this->keeper(null))
            ->get('/admin/stock')
            ->viewData('page')['props'];

        $this->assertNull($props['branch']);
        $this->assertCount(2, $props['stores']);
    }

    public function test_the_valuation_counts_only_this_branch(): void
    {
        $this->stockAt($this->mine, 4);
        $this->stockAt($this->theirs, 6);

        $mine = $this->actingAs($this->keeper($this->mine))
            ->get('/admin/stock')->viewData('page')['props']['summary'];

        $whole = $this->actingAs($this->keeper(null))
            ->get('/admin/stock')->viewData('page')['props']['summary'];

        $this->assertSame(4, $mine['units']);
        $this->assertTrue($mine['branch_scoped']);
        $this->assertSame(10, $whole['units']);
        $this->assertFalse($whole['branch_scoped']);
    }

    public function test_the_branch_breakdown_hides_other_branches(): void
    {
        $this->stockAt($this->mine, 4);
        $this->stockAt($this->theirs, 6);

        $rows = $this->actingAs($this->keeper($this->mine))
            ->getJson("/api/admin/stock/products/{$this->product->id}/branches")
            ->assertSuccessful()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Uttara', $rows[0]['store']);
    }

    // --- what they may do -----------------------------------------------

    public function test_receiving_into_another_branch_is_refused(): void
    {
        $this->actingAs($this->keeper($this->mine))
            ->postJson('/api/admin/stock/receipts', [
                'store_id' => $this->theirs->id,
                'lines' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 100]],
            ])->assertStatus(403);

        $this->assertSame(0, (int) $this->product->fresh()->stock_quantity);
    }

    public function test_receiving_into_your_own_branch_works(): void
    {
        $this->actingAs($this->keeper($this->mine))
            ->postJson('/api/admin/stock/receipts', [
                'store_id' => $this->mine->id,
                'lines' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 100]],
            ])->assertSuccessful();

        $this->assertSame(5, (int) $this->product->fresh()->stock_quantity);
    }

    /** A delivery with no branch named lands at theirs, not "wherever". */
    public function test_a_delivery_with_no_branch_lands_at_their_own(): void
    {
        $this->actingAs($this->keeper($this->mine))
            ->postJson('/api/admin/stock/receipts', [
                'lines' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_cost' => 100]],
            ])->assertSuccessful();

        $this->assertDatabaseHas('product_stock', [
            'product_id' => $this->product->id,
            'store_id' => $this->mine->id,
            'quantity' => 3,
        ]);
    }

    public function test_adjusting_another_branch_is_refused(): void
    {
        $this->stockAt($this->theirs, 10);

        $this->actingAs($this->keeper($this->mine))
            ->postJson('/api/admin/stock/adjust', [
                'product_id' => $this->product->id,
                'quantity' => -3,
                'reason' => array_key_first(StockService::ADJUSTMENT_REASONS),
                'store_id' => $this->theirs->id,
            ])->assertStatus(403);

        $this->assertSame(10, (int) $this->product->fresh()->stock_quantity);
    }

    /**
     * Sending stock away empties a shelf somebody else answers for.
     */
    public function test_transferring_out_of_another_branch_is_refused(): void
    {
        $this->stockAt($this->theirs, 10);

        $this->actingAs($this->keeper($this->mine))
            ->postJson('/api/admin/stock/transfer', [
                'product_id' => $this->product->id,
                'quantity' => 5,
                'from_store_id' => $this->theirs->id,
                'to_store_id' => $this->mine->id,
            ])->assertStatus(403);

        $this->assertDatabaseHas('product_stock', [
            'store_id' => $this->theirs->id,
            'quantity' => 10,
        ]);
    }

    public function test_transferring_out_of_your_own_branch_works(): void
    {
        $this->stockAt($this->mine, 10);

        $this->actingAs($this->keeper($this->mine))
            ->postJson('/api/admin/stock/transfer', [
                'product_id' => $this->product->id,
                'quantity' => 4,
                'from_store_id' => $this->mine->id,
                'to_store_id' => $this->theirs->id,
            ])->assertSuccessful();

        $this->assertDatabaseHas('product_stock', ['store_id' => $this->mine->id, 'quantity' => 6]);
        $this->assertDatabaseHas('product_stock', ['store_id' => $this->theirs->id, 'quantity' => 4]);
    }

    public function test_an_unconfined_person_may_still_move_stock_anywhere(): void
    {
        $this->stockAt($this->theirs, 10);

        $this->actingAs($this->keeper(null))
            ->postJson('/api/admin/stock/transfer', [
                'product_id' => $this->product->id,
                'quantity' => 4,
                'from_store_id' => $this->theirs->id,
                'to_store_id' => $this->mine->id,
            ])->assertSuccessful();
    }

    public function test_the_movement_history_is_their_branch_only(): void
    {
        $this->stockAt($this->mine, 4);
        $this->stockAt($this->theirs, 6);

        $movements = $this->actingAs($this->keeper($this->mine))
            ->getJson("/api/admin/stock/products/{$this->product->id}/movements")
            ->assertSuccessful()
            ->json('data.movements.data');

        $this->assertCount(1, $movements);
        $this->assertSame($this->mine->id, $movements[0]['store_id']);
    }
}
