<?php

namespace Tests\Unit\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CategoryService $categoryService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categoryService = app(CategoryService::class);
    }

    public function test_get_featured_categories_returns_array_of_categories(): void
    {
        Category::create([
            'name' => 'Processor / CPU',
            'slug' => 'cpu',
            'is_active' => true,
        ]);

        $featured = $this->categoryService->getFeaturedCategories();

        $this->assertIsArray($featured);
        $this->assertNotEmpty($featured);
        $this->assertArrayHasKey('name', $featured[0]);
        $this->assertArrayHasKey('slug', $featured[0]);
        $this->assertArrayHasKey('icon', $featured[0]);
    }

    public function test_get_mega_menu_tree_returns_collection_with_structure(): void
    {
        $parent = Category::create([
            'name' => 'Components',
            'slug' => 'components',
            'badge' => 'HOT',
            'is_active' => true,
        ]);

        $child = Category::create([
            'name' => 'Graphics Card',
            'slug' => 'graphics-card',
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);

        $leaf = Category::create([
            'name' => 'RTX 4090 Arena',
            'slug' => 'rtx-4090-arena',
            'parent_id' => $child->id,
            'is_active' => true,
        ]);

        /*
         * The menu only offers categories that have something to sell — an
         * empty branch is a dead end for the shopper — so the tree needs one
         * product before it will describe this structure at all.
         */
        Product::create([
            'category_id' => $leaf->id,
            'brand_id' => Brand::create(['name' => 'ASUS', 'slug' => 'asus'])->id,
            'name' => 'ASUS ROG RTX 4090',
            'slug' => 'asus-rog-rtx-4090',
            'price' => 250000,
            'stock_quantity' => 2,
            'is_active' => true,
        ]);

        $tree = $this->categoryService->getMegaMenuTree();

        $this->assertNotEmpty($tree);
        $first = $tree->firstWhere('slug', 'components');
        $this->assertNotNull($first);
        $this->assertEquals('HOT', $first['badge']);
        $this->assertNotEmpty($first['subcategories']);
    }

    public function test_get_descendant_ids_returns_correct_ids(): void
    {
        $parent = Category::create([
            'name' => 'Laptops',
            'slug' => 'laptops',
            'is_active' => true,
        ]);

        $child = Category::create([
            'name' => 'Gaming Laptops',
            'slug' => 'gaming-laptops',
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);

        $ids = $this->categoryService->getDescendantIds('laptops');

        $this->assertContains($parent->id, $ids);
        $this->assertContains($child->id, $ids);
    }
}
