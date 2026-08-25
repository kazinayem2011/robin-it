<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The messages controllers flash have to reach the browser.
 *
 * Writes across the admin and the account said back()->with('success', ...) on
 * nearly every action — 31 of them — and none were displayed, because `flash`
 * was never shared with Inertia. Saving a profile or an address looked exactly
 * like doing nothing.
 */
class FlashMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_flash_bag_is_shared_with_every_page(): void
    {
        $props = $this->get('/')->viewData('page')['props'];

        $this->assertArrayHasKey('flash', $props);
    }

    public function test_a_flashed_success_reaches_the_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/account/profile', [
            'name' => 'Rahim Chowdhury',
            'email' => $user->email,
            'phone' => '01712345678',
        ]);

        $props = $this->actingAs($user)->get('/dashboard/profile')
            ->viewData('page')['props'];

        $this->assertSame('Profile updated successfully.', $props['flash']['success']);
    }

    public function test_nothing_is_flashed_when_nothing_happened(): void
    {
        $props = $this->actingAs(User::factory()->create())
            ->get('/dashboard/profile')
            ->viewData('page')['props'];

        $this->assertNull($props['flash']['success']);
        $this->assertNull($props['flash']['error']);
    }
}
