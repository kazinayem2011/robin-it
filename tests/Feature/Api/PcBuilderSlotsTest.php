<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The slots a build is assembled from.
 *
 * Order and grouping are the point: someone building a machine works down from
 * the processor, and peripherals are a separate decision from the parts that
 * have to fit together.
 */
class PcBuilderSlotsTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $slug, string $name, int $products = 0): Category
    {
        $category = Category::create(['slug' => $slug, 'name' => $name, 'is_active' => true]);

        for ($i = 0; $i < $products; $i++) {
            Product::create([
                'category_id' => $category->id,
                'name' => "{$name} {$i}",
                'slug' => "{$slug}-{$i}",
                'price' => 1000,
                'stock_quantity' => 5,
                'is_active' => true,
            ]);
        }

        return $category;
    }

    private function slots(): array
    {
        return app(ProductService::class)->getPcBuilderCategories();
    }

    public function test_core_parts_come_before_peripherals(): void
    {
        $this->category('cpu', 'Processor', 2);
        $this->category('motherboard', 'Motherboard', 2);
        $this->category('keyboards', 'Keyboards', 1);

        $groups = collect($this->slots())->pluck('group')->unique()->values()->all();

        $this->assertSame(['core', 'peripherals'], $groups);
    }

    /** A shopper works down from the processor, not alphabetically. */
    public function test_the_processor_comes_first(): void
    {
        $this->category('ram', 'Memory', 1);
        $this->category('cpu', 'Processor', 1);
        $this->category('motherboard', 'Motherboard', 1);

        $this->assertSame('cpu', $this->slots()[0]['id']);
    }

    public function test_an_optional_slot_with_nothing_in_it_is_dropped(): void
    {
        $this->category('cpu', 'Processor', 1);
        // Optional and empty — offering it would open an empty picker.
        $this->category('keyboards', 'Keyboards', 0);

        $ids = collect($this->slots())->pluck('id');

        $this->assertContains('cpu', $ids);
        $this->assertNotContains('keyboards', $ids);
    }

    /**
     * Hiding a required slot would make a build look completable when the shop
     * cannot actually supply a part for it.
     */
    public function test_a_required_slot_with_nothing_in_it_is_kept_and_flagged(): void
    {
        $this->category('cpu', 'Processor', 1);
        $this->category('pc-case', 'PC Case', 0);

        $case = collect($this->slots())->firstWhere('id', 'pc-case');

        $this->assertNotNull($case, 'a required slot disappeared');
        $this->assertSame(0, $case['available']);
        $this->assertTrue($case['required']);
    }

    public function test_the_parts_that_must_fit_together_are_marked_required(): void
    {
        foreach (['cpu' => 'Processor', 'motherboard' => 'Motherboard', 'ram' => 'Memory',
            'storage' => 'Storage', 'graphics-card' => 'Graphics Card'] as $slug => $name) {
            $this->category($slug, $name, 1);
        }

        $required = collect($this->slots())->where('required', true)->pluck('id')->all();

        $this->assertContains('cpu', $required);
        $this->assertContains('motherboard', $required);
        // A processor with built-in graphics needs no card.
        $this->assertNotContains('graphics-card', $required);
    }

    /** The same "genuine product with warranty" line on every row said nothing. */
    public function test_core_slots_explain_why_they_matter(): void
    {
        $this->category('cpu', 'Processor', 1);
        $this->category('ram', 'Memory', 1);

        $slots = collect($this->slots());

        $this->assertStringContainsString('socket', $slots->firstWhere('id', 'cpu')['hint']);
        $this->assertStringContainsString('DDR', $slots->firstWhere('id', 'ram')['hint']);
    }

    public function test_the_endpoint_returns_the_same_shape(): void
    {
        $this->category('cpu', 'Processor', 1);

        $slot = $this->getJson('/api/pc-builder/categories')
            ->assertStatus(200)
            ->json('data.0');

        foreach (['id', 'category_id', 'name', 'category_slug', 'required', 'group', 'icon', 'available'] as $key) {
            $this->assertArrayHasKey($key, $slot, "the builder UI reads {$key}");
        }
    }

    public function test_an_inactive_category_is_not_offered(): void
    {
        $this->category('cpu', 'Processor', 1);
        $this->category('ram', 'Memory', 1)->update(['is_active' => false]);

        $this->assertNotContains('ram', collect($this->slots())->pluck('id'));
    }
}
