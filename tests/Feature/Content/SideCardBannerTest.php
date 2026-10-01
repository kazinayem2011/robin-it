<?php

namespace Tests\Feature\Content;

use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Banners → Beside the slider: the two picture cards to the right of the hero
 * slider, as on StarTech's home. Saved under their own placement, hero_side.
 */
class SideCardBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_side_card_is_saved_under_its_own_placement(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/banners', [
            'title' => 'Complain & Feedback',
            'image_path' => '/storage/uploads/banners/side.jpg',
            'link_url' => '/contact',
            'position' => 'hero_side',
            'sort_order' => 1,
            'is_active' => true,
        ])->assertSuccessful();

        $this->assertSame('hero_side', Banner::firstWhere('title', 'Complain & Feedback')->position);
    }

    public function test_an_unknown_placement_is_still_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/banners', [
            'title' => 'Somewhere', 'image_path' => '/x.jpg', 'position' => 'sidebar',
        ])->assertStatus(422);
    }
}
