<?php

namespace Tests\Feature\Dashboard;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The overview's two list panels.
 *
 * Both were written for a shop with a handful of things wrong and neither said
 * anything when there was nothing to show or too much.
 */
class OverviewPanelsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Products at or under the threshold the panel watches.
     *
     * @param  callable|null  $quantity  how much each one has, by index
     */
    private function lowStock(int $howMany, ?callable $quantity = null): void
    {
        $category = Category::create(['name' => 'CPU', 'slug' => 'cpu', 'is_active' => true]);

        for ($i = 1; $i <= $howMany; $i++) {
            Product::create([
                'category_id' => $category->id,
                'name' => "Part {$i}",
                'slug' => "part-{$i}",
                'price' => 1000,
                'stock_quantity' => $quantity ? $quantity($i) : 0,
                'is_active' => true,
            ]);
        }
    }

    private function overview(): array
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/dashboard')
            ->assertOk()
            ->viewData('page')['props'];
    }

    /**
     * The panel is capped; the number beside "Low stock" is not.
     *
     * These were the same collection, so capping the list would have quietly
     * capped the count — the dashboard would have reported eight products
     * needing attention out of thirty-six, which is worse than the long list
     * it replaced.
     */
    public function test_the_low_stock_count_is_the_real_total_not_the_list_length(): void
    {
        $this->lowStock(36);

        $props = $this->overview();

        $this->assertCount(8, $props['lowStockProducts']);
        $this->assertSame(36, $props['metrics']['low_stock_count']);
        $this->assertSame(36, $props['lowStockTotal']);
    }

    /** Cutting the list is only safe if it keeps the ones that matter. */
    public function test_the_emptiest_shelves_come_first(): void
    {
        // 0 through 10, all at or under the threshold; 11 and 12 are above it.
        $this->lowStock(13, fn ($i) => $i - 1);

        $props = $this->overview();

        $this->assertSame(11, $props['lowStockTotal']);
        $this->assertSame(
            [0, 1, 2, 3, 4, 5, 6, 7],
            collect($props['lowStockProducts'])->pluck('stock_quantity')->all()
        );
    }

    public function test_a_short_list_is_not_padded_or_cut(): void
    {
        $this->lowStock(3);

        $props = $this->overview();

        $this->assertCount(3, $props['lowStockProducts']);
        $this->assertSame(3, $props['lowStockTotal']);
    }

    /**
     * The panel used to load every low-stock product with its brand and all
     * its images. A shop whose ledger has just been reset has every product
     * under the threshold, which made this the heaviest query on the page.
     */
    public function test_a_full_catalogue_of_low_stock_does_not_load_the_catalogue(): void
    {
        $this->lowStock(200);

        $this->assertCount(8, $this->overview()['lowStockProducts']);
    }

    public function test_a_shop_with_nothing_low_sends_an_empty_list(): void
    {
        $props = $this->overview();

        $this->assertCount(0, $props['lowStockProducts']);
        $this->assertSame(0, $props['lowStockTotal']);
        $this->assertCount(0, $props['recentOrders']);
    }
}
