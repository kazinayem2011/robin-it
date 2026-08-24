<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\PcCompatibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Knowing when the compatibility engine cannot actually check anything.
 *
 * It treats a missing spec as "unknown" rather than a failure, which is right
 * when checking a build and useless when stocking a catalogue: the shop cannot
 * tell "these parts fit" from "we never said". Two motherboards on this
 * catalogue had no Form Factor and nothing anywhere said so.
 */
class CompatibilitySpecGapsTest extends TestCase
{
    use RefreshDatabase;

    private function builderPart(string $categorySlug, string $name, array $specs = []): Product
    {
        $root = Category::firstOrCreate(
            ['slug' => 'components'],
            ['name' => 'Components', 'is_active' => true]
        );

        $category = Category::firstOrCreate(
            ['slug' => $categorySlug],
            ['name' => ucfirst($categorySlug), 'parent_id' => $root->id, 'is_active' => true]
        );

        $product = Product::create([
            'category_id' => $category->id, 'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'price' => 1000, 'stock_quantity' => 1, 'is_active' => true,
        ]);

        foreach ($specs as $specName => $value) {
            $product->specifications()->create(['name' => $specName, 'value' => $value]);
        }

        return $product->fresh('specifications');
    }

    private function service(): PcCompatibilityService
    {
        return app(PcCompatibilityService::class);
    }

    public function test_a_motherboard_without_a_form_factor_is_reported(): void
    {
        $board = $this->builderPart('motherboard', 'Some Board', [
            'Socket' => 'LGA1700',
            'Memory Type' => 'DDR5',
        ]);

        $this->assertSame(['Form Factor'], $this->service()->missingSpecsFor($board));
    }

    public function test_a_fully_specified_part_reports_nothing(): void
    {
        $board = $this->builderPart('motherboard', 'Complete Board', [
            'Socket' => 'LGA1700',
            'Memory Type' => 'DDR5',
            'Form Factor' => 'ATX',
        ]);

        $this->assertSame([], $this->service()->missingSpecsFor($board));
    }

    /** The engine accepts several names for the same fact; so must this. */
    public function test_an_accepted_alias_counts_as_present(): void
    {
        // "Power Draw" is one of the names the TDP check reads.
        $cpu = $this->builderPart('cpu', 'Some CPU', [
            'Socket' => 'AM5',
            'Power Draw' => '120W',
        ]);

        $this->assertSame([], $this->service()->missingSpecsFor($cpu));
    }

    public function test_a_product_outside_the_builder_is_not_flagged(): void
    {
        $laptop = $this->builderPart('laptops', 'A Laptop');

        $this->assertSame([], $this->service()->missingSpecsFor($laptop));
        $this->assertNull($this->service()->slotForProduct($laptop));
    }

    /** The slot is found by walking up, so a sub-category still resolves. */
    public function test_a_sub_category_resolves_to_its_builder_slot(): void
    {
        $ram = Category::firstOrCreate(['slug' => 'ram'], ['name' => 'RAM', 'is_active' => true]);
        $child = Category::create([
            'slug' => 'ddr5-6000', 'name' => 'DDR5 6000',
            'parent_id' => $ram->id, 'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $child->id, 'name' => 'A Memory Kit',
            'slug' => 'a-memory-kit', 'price' => 1000,
            'stock_quantity' => 1, 'is_active' => true,
        ]);

        $this->assertSame('ram', $this->service()->slotForProduct($product->fresh()));
        $this->assertSame(['Memory Type'], $this->service()->missingSpecsFor($product->fresh()));
    }

    public function test_the_admin_product_list_reports_the_gaps(): void
    {
        $this->builderPart('motherboard', 'Unspecified Board', ['Socket' => 'AM5']);

        $props = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/products')
            ->viewData('page')['props'];

        $row = collect($props['products']['data'])->firstWhere('name', 'Unspecified Board');

        $this->assertNotNull($row);
        $this->assertContains('Memory Type', $row['missing_specs']);
        $this->assertContains('Form Factor', $row['missing_specs']);
    }
}
