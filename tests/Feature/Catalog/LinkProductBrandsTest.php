<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Filling in products.brand_id, which nothing ever wrote to.
 *
 * The shop's Brand filter is built from the products in scope, so an empty
 * column means the facet renders correctly and is always empty. The catalogue
 * knows the answer twice over — the category a product sits under is often the
 * maker, and the name nearly always opens with it.
 */
class LinkProductBrandsTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $name): Category
    {
        return Category::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'is_active' => true,
        ]);
    }

    private function product(string $name, Category $category): Product
    {
        return Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'price' => 1000,
            'stock_quantity' => 1,
            'is_active' => true,
        ]);
    }

    public function test_a_product_filed_under_a_brand_category_gets_that_brand(): void
    {
        $asus = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);
        $product = $this->product('TUF Gaming A15', $this->category('ASUS'));

        $this->artisan('catalogue:link-brands')
            ->expectsConfirmation('Apply this?', 'yes')
            ->assertSuccessful();

        $this->assertSame($asus->id, $product->fresh()->brand_id);
    }

    public function test_a_brand_named_in_the_title_is_found(): void
    {
        $logitech = Brand::create(['name' => 'Logitech', 'slug' => 'logitech']);
        $product = $this->product('Logitech MX Master 3S Wireless Mouse', $this->category('Mouse'));

        $this->artisan('catalogue:link-brands')
            ->expectsConfirmation('Apply this?', 'yes')
            ->assertSuccessful();

        $this->assertSame($logitech->id, $product->fresh()->brand_id);
    }

    /**
     * The reason a maker is never invented from a category name: the catalogue
     * is full of seeded rows named after the shelf they sit on, and treating
     * that as a brand produced "AI PC" and "MacBook" as manufacturers.
     */
    public function test_a_product_type_never_becomes_a_brand(): void
    {
        $this->product('Sample AI PC', $this->category('AI PC'));
        $this->product('Sample MacBook', $this->category('MacBook'));

        $this->artisan('catalogue:link-brands')->assertSuccessful();

        $this->assertSame(0, Brand::count(), 'A category name was turned into a brand.');
        $this->assertSame(2, Product::whereNull('brand_id')->count());
    }

    /** "Fantech" is not "Fan", and "PowerColor" is not "Power". */
    public function test_a_brand_is_matched_on_whole_words_only(): void
    {
        Brand::create(['name' => 'Fan', 'slug' => 'fan']);
        $product = $this->product('Fantech Aria XD7 Wireless Mouse', $this->category('Mouse'));

        $this->artisan('catalogue:link-brands')->assertSuccessful();

        $this->assertNull($product->fresh()->brand_id);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        Brand::create(['name' => 'ASUS', 'slug' => 'asus']);
        $product = $this->product('TUF Gaming A15', $this->category('ASUS'));

        $this->artisan('catalogue:link-brands', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($product->fresh()->brand_id);
    }

    public function test_a_brand_already_set_by_hand_is_left_alone(): void
    {
        $asus = Brand::create(['name' => 'ASUS', 'slug' => 'asus']);
        $msi = Brand::create(['name' => 'MSI', 'slug' => 'msi']);

        // Somebody filed this under MSI in the admin, whatever the name says.
        $product = $this->product('ASUS ROG Strix', $this->category('Laptop'));
        $product->update(['brand_id' => $msi->id]);

        // Nothing is even considered: the command only looks at products with
        // no brand, so a hand-filed one is never second-guessed.
        $this->artisan('catalogue:link-brands')->assertSuccessful();

        $this->assertSame($msi->id, $product->fresh()->brand_id);
        $this->assertNotSame($asus->id, $product->fresh()->brand_id);
    }
}
