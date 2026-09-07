<?php

namespace Tests\Feature\Storefront;

use App\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The homepage brand row reads the brands table.
 *
 * It was a hardcoded list of names and file paths in a component, kept apart
 * from the brands an admin manages: uploading a logo wrote logo_path, which the
 * mega menu reads and that row did not. So the admin could change the menu and
 * never the homepage — and five of those files were the wrong company's marks,
 * with no way to correct them short of a deploy.
 */
class HomepageBrandsTest extends TestCase
{
    use RefreshDatabase;

    private function props(): array
    {
        return $this->get('/')->assertStatus(200)->viewData('page')['props'];
    }

    public function test_the_homepage_is_given_the_featured_brands(): void
    {
        Brand::create(['name' => 'Intel', 'slug' => 'intel', 'is_featured' => true]);
        Brand::create(['name' => 'Obscure Co', 'slug' => 'obscure', 'is_featured' => false]);

        $names = collect($this->props()['brands'])->pluck('name')->all();

        $this->assertContains('Intel', $names);
        $this->assertNotContains('Obscure Co', $names, 'an unfeatured brand reached the homepage');
    }

    /** The flag is the curation, so unticking one takes it off the homepage. */
    public function test_unfeaturing_a_brand_removes_it_from_the_row(): void
    {
        $brand = Brand::create(['name' => 'Kingston', 'slug' => 'kingston', 'is_featured' => true]);

        $this->assertContains('Kingston', collect($this->props()['brands'])->pluck('name')->all());

        $brand->update(['is_featured' => false]);

        $this->assertNotContains('Kingston', collect($this->props()['brands'])->pluck('name')->all());
    }

    /**
     * The row draws a name where there is no logo, so it has to be told which
     * brands have one — a missing key would send it to a broken image.
     */
    public function test_each_brand_carries_the_logo_the_row_needs(): void
    {
        Brand::create([
            'name' => 'AMD',
            'slug' => 'amd',
            'is_featured' => true,
            'logo_path' => '/images/brands/amd.png',
        ]);

        $brand = collect($this->props()['brands'])->firstWhere('name', 'AMD');

        $this->assertSame('/images/brands/amd.png', $brand['logo_path']);
        $this->assertSame('amd', $brand['slug']);
    }

    public function test_an_uploaded_logo_reaches_the_homepage(): void
    {
        $brand = Brand::create(['name' => 'MSI', 'slug' => 'msi', 'is_featured' => true]);

        $this->assertNull(collect($this->props()['brands'])->firstWhere('name', 'MSI')['logo_path']);

        // What /admin/brands writes once somebody uploads the real artwork.
        $brand->update(['logo_path' => '/storage/uploads/brands/real-msi.png']);

        $this->assertSame(
            '/storage/uploads/brands/real-msi.png',
            collect($this->props()['brands'])->firstWhere('name', 'MSI')['logo_path']
        );
    }
}
