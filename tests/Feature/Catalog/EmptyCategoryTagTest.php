<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin tree says which categories the menu leaves out, and why.
 *
 * The mega menu hides a category with no products beneath it. "Used Laptop",
 * just made and still empty, was missing from the header with nothing in the
 * admin to say so — it looked like a broken menu.
 */
class EmptyCategoryTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tree_is_told_which_categories_are_empty(): void
    {
        $used = Category::create(['name' => 'Used Laptop', 'slug' => 'used-laptop', 'is_active' => true]);
        $usedSub = Category::create(['name' => 'Used Sub', 'slug' => 'used-sub', 'parent_id' => $used->id, 'is_active' => true]);

        $laptop = Category::create(['name' => 'Laptop', 'slug' => 'laptop', 'is_active' => true]);
        $gaming = Category::create(['name' => 'Gaming', 'slug' => 'gaming', 'parent_id' => $laptop->id, 'is_active' => true]);
        $product = Product::create([
            'category_id' => $gaming->id, 'name' => 'ROG', 'slug' => 'rog',
            'price' => 1000, 'stock_quantity' => 1, 'is_active' => true,
        ]);
        $product->categories()->sync([$gaming->id]);

        $offer = Category::create(['name' => 'Deals', 'slug' => 'deals', 'is_active' => true, 'is_offer' => true]);

        $admin = User::factory()->create(['role' => Roles::OWNER, 'is_active' => true]);

        $this->actingAs($admin)->get('/admin/categories')
            ->assertInertia(function ($page) use ($used, $usedSub, $laptop, $gaming, $offer) {
                $empty = $page->toArray()['props']['emptyIds'];

                $this->assertContains($used->id, $empty);
                $this->assertContains($usedSub->id, $empty);
                // Stocked below, so it is in the menu.
                $this->assertNotContains($laptop->id, $empty);
                $this->assertNotContains($gaming->id, $empty);
                // An offer category is always shown.
                $this->assertNotContains($offer->id, $empty);
            });
    }
}
