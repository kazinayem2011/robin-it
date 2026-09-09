<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The makers on a shelf, in a line across the top of it.
 *
 * How the trade lays out a category page, and the shortest route a shopper
 * has: most people arriving at Laptop already know whose laptop they want.
 */
class CategoryBrandRowTest extends TestCase
{
    use RefreshDatabase;

    private Category $laptop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->laptop = Category::create([
            'name' => 'Laptop', 'slug' => 'laptop', 'position' => 0, 'is_active' => true,
        ]);
    }

    private function sub(string $name, int $position): Category
    {
        return Category::create([
            'name' => $name, 'slug' => str($name)->slug(),
            'parent_id' => $this->laptop->id, 'position' => $position, 'is_active' => true,
        ]);
    }

    private function brandShelf(Brand $brand, Category $parent): Category
    {
        $shelf = Category::create([
            'name' => $brand->name,
            'slug' => str($brand->name.' '.$parent->name)->slug(),
            'parent_id' => $parent->id, 'brand_id' => $brand->id, 'is_active' => true,
        ]);

        $product = Product::create([
            'name' => $brand->name.' on '.$parent->name,
            'slug' => str($brand->name.'-'.$parent->name)->slug().'-item',
            'category_id' => $parent->id, 'brand_id' => $brand->id,
            'price' => 1000, 'stock_quantity' => 3, 'is_active' => true,
        ]);
        $product->categories()->syncWithoutDetaching([$parent->id]);

        return $shelf;
    }

    private function row(string $slug = 'laptop'): array
    {
        return app(CategoryService::class)->brandShelvesIn($slug);
    }

    public function test_it_lists_the_makers_stocked_on_the_shelf(): void
    {
        $all = $this->sub('All Laptop', 0);
        $this->brandShelf(Brand::create(['name' => 'ASUS', 'slug' => 'asus']), $all);
        $this->brandShelf(Brand::create(['name' => 'MSI', 'slug' => 'msi']), $all);

        $this->assertSame(['ASUS', 'MSI'], collect($this->row())->pluck('name')->sort()->values()->all());
    }

    /**
     * The one that took a wrong turn first time round.
     *
     * ASUS stands on All Laptop and on Laptop Bag. Deduping by anything but
     * the parent's own place in the menu gave the row an ASUS that led to
     * laptop bags — right maker, wrong shelf, and nothing on the page to say
     * that is what happened.
     */
    public function test_a_maker_on_several_shelves_links_to_the_first_of_them(): void
    {
        $all = $this->sub('All Laptop', 0);
        $bags = $this->sub('Laptop Bag', 3);

        $asus = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);
        $this->brandShelf($asus, $bags);
        $this->brandShelf($asus, $all);

        $row = $this->row();

        $this->assertCount(1, $row, 'ASUS should appear once, not once per shelf');
        $this->assertSame('asus-all-laptop', $row[0]['slug']);
    }

    /** The row reads in the shop's order, not alphabetically. */
    public function test_it_follows_the_shop_order(): void
    {
        $all = $this->sub('All Laptop', 0);
        $gaming = $this->sub('Gaming Laptop', 1);

        $this->brandShelf(Brand::create(['name' => 'Zebra', 'slug' => 'z']), $all);
        $this->brandShelf(Brand::create(['name' => 'Alpha', 'slug' => 'a']), $gaming);

        $this->assertSame(['Zebra', 'Alpha'], collect($this->row())->pluck('name')->all());
    }

    /** An empty shelf is not a way in, so it is not offered as one. */
    public function test_it_leaves_out_a_maker_with_nothing_on_the_shelf(): void
    {
        $all = $this->sub('All Laptop', 0);
        $this->brandShelf(Brand::create(['name' => 'ASUS', 'slug' => 'asus']), $all);

        Category::create([
            'name' => 'Empty', 'slug' => 'empty-all-laptop',
            'parent_id' => $all->id,
            'brand_id' => Brand::create(['name' => 'Empty', 'slug' => 'empty'])->id,
            'is_active' => true,
        ]);

        $this->assertSame(['ASUS'], collect($this->row())->pluck('name')->all());
    }

    public function test_it_leaves_out_a_hidden_shelf(): void
    {
        $all = $this->sub('All Laptop', 0);
        $hidden = $this->brandShelf(Brand::create(['name' => 'ASUS', 'slug' => 'asus']), $all);
        $hidden->update(['is_active' => false]);

        $this->assertSame([], $this->row());
    }

    /** Another department's makers are not this department's. */
    public function test_it_does_not_reach_into_another_department(): void
    {
        $all = $this->sub('All Laptop', 0);
        $this->brandShelf(Brand::create(['name' => 'ASUS', 'slug' => 'asus']), $all);

        $monitors = Category::create(['name' => 'Monitor', 'slug' => 'monitor', 'is_active' => true]);
        $mSub = Category::create([
            'name' => 'All Monitor', 'slug' => 'all-monitor',
            'parent_id' => $monitors->id, 'is_active' => true,
        ]);
        $this->brandShelf(Brand::create(['name' => 'LG', 'slug' => 'lg']), $mSub);

        $this->assertSame(['ASUS'], collect($this->row())->pluck('name')->all());
        $this->assertSame(['LG'], collect($this->row('monitor'))->pluck('name')->all());
    }

    public function test_the_endpoint_answers_with_the_row(): void
    {
        $all = $this->sub('All Laptop', 0);
        $this->brandShelf(Brand::create(['name' => 'ASUS', 'slug' => 'asus']), $all);

        $this->getJson('/api/categories/laptop/brands')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'ASUS')
            ->assertJsonPath('data.0.slug', 'asus-all-laptop');
    }

    public function test_a_shelf_nobody_has_is_an_empty_row_not_an_error(): void
    {
        $this->getJson('/api/categories/nothing-here/brands')
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
