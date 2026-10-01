<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\User;
use App\Services\CategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each Featured Tech Categories card has its own colour: the category's own
 * from the admin, else the next of a fixed palette, so neighbours differ.
 * They were keyed by seeded slugs the real categories do not use, so every
 * card came out the same red.
 */
class CategoryCardColourTest extends TestCase
{
    use RefreshDatabase;

    public function test_cards_take_distinct_palette_colours_unless_one_is_set(): void
    {
        foreach (['Desktop', 'Laptop', 'Monitor'] as $i => $name) {
            Category::create(['name' => $name, 'slug' => strtolower($name), 'is_active' => true, 'position' => $i]);
        }
        Category::where('slug', 'monitor')->update(['accent_color' => '#123456']);
        CategoryService::flush();

        $cards = collect(app(CategoryService::class)->getFeaturedCategories())->keyBy('slug');

        $this->assertSame(CategoryService::CARD_PALETTE[0], $cards['desktop']['color']);
        $this->assertSame(CategoryService::CARD_PALETTE[1], $cards['laptop']['color']);
        $this->assertSame('#123456', $cards['monitor']['color']);
        $this->assertArrayNotHasKey('count', $cards['desktop']);
    }

    public function test_the_admin_sets_and_clears_a_cards_colour(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $cat = Category::create(['name' => 'Camera', 'slug' => 'camera', 'is_active' => true]);

        $this->actingAs($admin)->patchJson("/api/admin/categories/{$cat->id}", [
            'name' => 'Camera', 'accent_color' => '#EA580C', 'is_active' => true,
        ])->assertOk();
        $this->assertSame('#ea580c', $cat->fresh()->accent_color);

        $this->actingAs($admin)->patchJson("/api/admin/categories/{$cat->id}", [
            'name' => 'Camera', 'accent_color' => null, 'is_active' => true,
        ])->assertOk();
        $this->assertNull($cat->fresh()->accent_color);

        $this->actingAs($admin)->patchJson("/api/admin/categories/{$cat->id}", [
            'name' => 'Camera', 'accent_color' => 'orange', 'is_active' => true,
        ])->assertStatus(422);
    }
}
