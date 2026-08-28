<?php

namespace Tests\Feature\Stock;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\Store;
use App\Models\User;
use App\Services\StockService;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Counting the shelves.
 *
 * Corrections could only be made one product at a time through a modal, which
 * is right for "this card arrived broken" and unusable for the thing shops
 * actually do — walk the aisle and count everything.
 */
class StockTakeTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    private Product $cpu;

    private Product $gpu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Roles::forget();

        $this->store = $this->makeStore('Uttara', 1);
        $this->other = $this->makeStore('Agrabad', 2);

        $this->cpu = $this->makeProduct('Test CPU', 'take-cpu');
        $this->gpu = $this->makeProduct('Test GPU', 'take-gpu');

        $this->receive($this->store, $this->cpu, 10, 1000);
        $this->receive($this->store, $this->gpu, 4, 50000);
    }

    private function makeStore(string $name, int $order): Store
    {
        return Store::create([
            'name' => $name, 'city' => 'Dhaka', 'address' => 'Test address',
            'phone' => '01711000000', 'is_active' => true, 'holds_stock' => true,
            'fulfils_online' => true, 'sort_order' => $order,
        ]);
    }

    private function makeProduct(string $name, string $slug): Product
    {
        return Product::create([
            'category_id' => Category::firstOrCreate(['slug' => 'cpu'], ['name' => 'CPU', 'is_active' => true])->id,
            'name' => $name, 'slug' => $slug, 'price' => 5000,
            'stock_quantity' => 0, 'is_active' => true,
        ]);
    }

    private function receive(Store $store, Product $product, int $qty, float $cost): void
    {
        app(StockService::class)->receive(
            ['store_id' => $store->id, 'received_on' => now()->toDateString()],
            [['product_id' => $product->id, 'quantity' => $qty, 'unit_cost' => $cost]]
        );
    }

    private function keeper(?Store $store = null): User
    {
        return User::factory()->create([
            'role' => Roles::STOREKEEPER, 'is_active' => true, 'store_id' => $store?->id,
        ]);
    }

    private function submitCount(User $as, array $lines, ?Store $at = null)
    {
        return $this->actingAs($as)->postJson('/api/admin/stock/count', [
            'store_id' => ($at ?? $this->store)->id,
            'lines' => $lines,
        ]);
    }

    // --- the sheet -------------------------------------------------------

    public function test_the_sheet_lists_what_the_branch_holds(): void
    {
        $props = $this->actingAs($this->keeper($this->store))
            ->get('/admin/stock/count')
            ->assertStatus(200)
            ->viewData('page')['props'];

        $this->assertCount(2, $props['lines']);
        $this->assertSame('Uttara', $props['store']['name']);

        $cpu = collect($props['lines'])->firstWhere('name', 'Test CPU');
        $this->assertSame(10, $cpu['system_quantity']);
        // So the screen can price a discrepancy as it is typed.
        $this->assertSame(1000.0, (float) $cpu['unit_cost']);
    }

    public function test_a_branch_holding_nothing_has_an_empty_sheet(): void
    {
        $props = $this->actingAs($this->keeper($this->other))
            ->get('/admin/stock/count')
            ->viewData('page')['props'];

        $this->assertCount(0, $props['lines']);
    }

    // --- applying a count ------------------------------------------------

    public function test_a_count_corrects_only_what_disagrees(): void
    {
        $this->submitCount($this->keeper(), [
            ['product_id' => $this->cpu->id, 'counted_quantity' => 8],   // two short
            ['product_id' => $this->gpu->id, 'counted_quantity' => 4],   // matches
        ])->assertStatus(201);

        $this->assertSame(8, (int) $this->cpu->fresh()->stock_quantity);
        $this->assertSame(4, (int) $this->gpu->fresh()->stock_quantity);

        $take = StockTake::sole();
        $this->assertSame(2, $take->lines_counted);
        $this->assertSame(1, $take->lines_changed);
        $this->assertSame(-2, $take->net_units);
        $this->assertSame('-2000.00', $take->value_change);

        // A movement that changes no balance is noise in the ledger.
        $this->assertCount(1, $take->movements);
    }

    public function test_a_count_that_matches_writes_nothing(): void
    {
        $this->submitCount($this->keeper(), [
            ['product_id' => $this->cpu->id, 'counted_quantity' => 10],
            ['product_id' => $this->gpu->id, 'counted_quantity' => 4],
        ])->assertStatus(201)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'everything matched'));

        $this->assertSame(0, StockTake::sole()->movements()->count());
    }

    public function test_finding_more_than_the_books_say_puts_them_back(): void
    {
        $this->submitCount($this->keeper(), [
            ['product_id' => $this->cpu->id, 'counted_quantity' => 13],
        ])->assertStatus(201);

        $this->assertSame(13, (int) $this->cpu->fresh()->stock_quantity);
        $this->assertSame(3, StockTake::sole()->net_units);
    }

    /** Zero is a count. Blank is not, and the screen never sends one. */
    public function test_counting_zero_empties_the_shelf(): void
    {
        $this->submitCount($this->keeper(), [
            ['product_id' => $this->cpu->id, 'counted_quantity' => 0],
        ])->assertStatus(201);

        $this->assertSame(0, (int) $this->cpu->fresh()->stock_quantity);
    }

    public function test_a_negative_count_is_refused(): void
    {
        $this->submitCount($this->keeper(), [
            ['product_id' => $this->cpu->id, 'counted_quantity' => -1],
        ])->assertStatus(422);

        $this->assertSame(10, (int) $this->cpu->fresh()->stock_quantity);
    }

    public function test_an_empty_count_is_refused(): void
    {
        $this->submitCount($this->keeper(), [])->assertStatus(422);
    }

    /**
     * The corrections belong to the count.
     *
     * A hundred lines counted in one morning should read as one count, not a
     * hundred unexplained corrections.
     */
    public function test_the_corrections_are_tied_to_the_count(): void
    {
        $this->submitCount($this->keeper(), [
            ['product_id' => $this->cpu->id, 'counted_quantity' => 7],
        ])->assertStatus(201);

        $take = StockTake::sole();
        $movement = StockMovement::where('type', StockMovement::ADJUSTMENT)->sole();

        $this->assertSame(StockTake::class, $movement->reference_type);
        $this->assertSame($take->id, $movement->reference_id);
        $this->assertSame('stock_take', $movement->reason);
        $this->assertSame($this->store->id, $movement->store_id);
        $this->assertStringContainsString($take->reference, $movement->note);
    }

    /**
     * A count half-applied is worse than one not applied at all, because
     * nobody can tell which half is real.
     */
    public function test_a_count_that_fails_partway_applies_nothing(): void
    {
        $this->submitCount($this->keeper(), [
            ['product_id' => $this->cpu->id, 'counted_quantity' => 6],
            ['product_id' => 999999, 'counted_quantity' => 3],
        ])->assertStatus(422);

        $this->assertSame(10, (int) $this->cpu->fresh()->stock_quantity);
        $this->assertSame(0, StockTake::count());
    }

    /**
     * Counted against the books as they are when the count is written, not as
     * the counter's browser was told they were — somebody may have sold one
     * while the sheet was being filled in.
     */
    public function test_a_sale_during_the_count_is_not_undone(): void
    {
        $keeper = $this->keeper();

        // The sheet said 10. Two sell while it is being filled in.
        app(StockService::class)->record($this->cpu, null, -2, StockMovement::SALE, [
            'store_id' => $this->store->id,
        ]);

        // The counter walks the shelf and finds 8, which is now correct.
        $this->submitCount($keeper, [
            ['product_id' => $this->cpu->id, 'counted_quantity' => 8],
        ])->assertStatus(201);

        $this->assertSame(8, (int) $this->cpu->fresh()->stock_quantity);
        // Nothing to correct: the books already said 8 by the time it applied.
        $this->assertSame(0, StockTake::sole()->lines_changed);
    }

    // --- who may do it ---------------------------------------------------

    public function test_a_keeper_may_only_count_their_own_branch(): void
    {
        $this->submitCount($this->keeper($this->store), [
            ['product_id' => $this->cpu->id, 'counted_quantity' => 5],
        ], $this->other)->assertStatus(403);

        $this->assertSame(0, StockTake::count());
    }

    public function test_counting_belongs_to_stock(): void
    {
        $support = User::factory()->create(['role' => Roles::SUPPORT, 'is_active' => true]);

        $this->actingAs($support)->get('/admin/stock/count')->assertRedirect(route('admin.dashboard'));
        $this->submitCount($support, [
            ['product_id' => $this->cpu->id, 'counted_quantity' => 5],
        ])->assertStatus(403);
    }

    // --- the log ---------------------------------------------------------

    public function test_the_adjustments_log_shows_what_a_count_corrected(): void
    {
        $this->submitCount($this->keeper(), [
            ['product_id' => $this->cpu->id, 'counted_quantity' => 7],
        ])->assertStatus(201);

        $props = $this->actingAs($this->keeper())
            ->get('/admin/stock/adjustments')
            ->assertStatus(200)
            ->viewData('page')['props'];

        $rows = $props['movements']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame(-3, $rows[0]['quantity']);
        $this->assertSame(-3000.0, (float) $rows[0]['value']);
        $this->assertSame(3, $props['summary']['units_lost']);
        $this->assertSame(-3000.0, (float) $props['summary']['value_change']);
    }

    public function test_the_log_is_confined_to_a_keepers_branch(): void
    {
        $this->receive($this->other, $this->cpu, 5, 1000);

        // A write-off at each branch.
        $this->submitCount($this->keeper(), [['product_id' => $this->cpu->id, 'counted_quantity' => 9]])
            ->assertStatus(201);
        $this->submitCount($this->keeper(), [['product_id' => $this->cpu->id, 'counted_quantity' => 4]], $this->other)
            ->assertStatus(201);

        $props = $this->actingAs($this->keeper($this->store))
            ->get('/admin/stock/adjustments')
            ->viewData('page')['props'];

        $this->assertCount(1, $props['movements']['data']);
        $this->assertSame('Uttara', $props['movements']['data'][0]['store']);
    }
}
