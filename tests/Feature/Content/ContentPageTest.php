<?php

namespace Tests\Feature\Content;

use App\Models\ContentPage;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\ContentPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pages the shop writes itself.
 *
 * About, privacy, terms and the return policy were footer links with nothing
 * behind them, and About's words lived in the JSX — so changing a sentence
 * about the business needed a developer and a deploy.
 */
class ContentPageTest extends TestCase
{
    use RefreshDatabase;

    private function seedPages(): void
    {
        $this->seed(ContentPageSeeder::class);
    }

    private function staff(string $role = Roles::MANAGER): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function footerPages(): array
    {
        return [
            'privacy' => ['/privacy', 'Privacy policy'],
            'terms' => ['/terms', 'Terms & conditions'],
            'returns' => ['/return-policy', 'Returns & refunds'],
        ];
    }

    #[DataProvider('footerPages')]
    public function test_the_pages_the_footer_links_to_are_served(string $url, string $title): void
    {
        $this->seedPages();

        $props = $this->get($url)->assertStatus(200)->viewData('page')['props'];

        $this->assertSame($title, $props['page']['title']);
        $this->assertNotEmpty($props['page']['body']);
    }

    public function test_about_takes_its_words_from_the_database(): void
    {
        $this->seedPages();

        ContentPage::where('slug', 'about')->update(['subtitle' => 'Something new']);

        $props = $this->get('/about')->assertStatus(200)->viewData('page')['props'];

        $this->assertSame('Something new', $props['page']['subtitle']);
        // The figures stay counted rather than written.
        $this->assertArrayHasKey('stats', $props);
    }

    public function test_an_unpublished_page_is_not_served(): void
    {
        $this->seedPages();
        ContentPage::where('slug', 'privacy')->update(['is_published' => false]);

        $this->get('/privacy')->assertStatus(404);
    }

    public function test_a_page_that_does_not_exist_is_a_404(): void
    {
        $this->get('/p/nothing-here')->assertStatus(404);
    }

    /**
     * Anything pasted in is cleaned as it is stored.
     *
     * Purified on the way in, so what is in the column is what is safe to
     * render and nothing downstream has to remember to clean it.
     */
    public function test_a_script_in_the_body_never_reaches_the_column(): void
    {
        $this->actingAs($this->staff())
            ->postJson('/api/admin/pages', [
                'slug' => 'nasty',
                'title' => 'Nasty',
                'body' => '<p>Fine</p><script>alert(1)</script><p onclick="steal()">Also fine</p>',
            ])->assertStatus(201);

        $body = ContentPage::where('slug', 'nasty')->value('body');

        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringContainsString('Fine', $body);
    }

    public function test_a_new_page_is_reachable_under_p(): void
    {
        $this->actingAs($this->staff())
            ->postJson('/api/admin/pages', [
                'slug' => 'delivery-areas',
                'title' => 'Where we deliver',
                'body' => '<p>All 64 districts.</p>',
            ])->assertStatus(201);

        $this->get('/p/delivery-areas')
            ->assertStatus(200)
            ->assertViewHas('page');
    }

    /**
     * A built-in page keeps its address.
     *
     * The footer links to /privacy and /terms by name, so letting the slug
     * move would break those links from a screen that gives no sign of it.
     */
    public function test_a_built_in_page_cannot_be_moved_or_deleted(): void
    {
        $this->seedPages();
        $privacy = ContentPage::where('slug', 'privacy')->sole();
        $staff = $this->staff();

        $this->actingAs($staff)
            ->patchJson("/api/admin/pages/{$privacy->id}", [
                'slug' => 'somewhere-else',
                'title' => 'Privacy policy',
                'body' => '<p>Updated.</p>',
            ])->assertSuccessful();

        $this->assertSame('privacy', $privacy->fresh()->slug);
        $this->assertStringContainsString('Updated.', $privacy->fresh()->body);

        $this->actingAs($staff)
            ->deleteJson("/api/admin/pages/{$privacy->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('content_pages', ['slug' => 'privacy']);
    }

    public function test_a_page_the_shop_added_can_be_removed(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->postJson('/api/admin/pages', [
            'slug' => 'temporary', 'title' => 'Temporary', 'body' => '<p>x</p>',
        ])->assertStatus(201);

        $page = ContentPage::where('slug', 'temporary')->sole();

        $this->actingAs($staff)
            ->deleteJson("/api/admin/pages/{$page->id}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('content_pages', ['slug' => 'temporary']);
    }

    public function test_the_address_must_look_like_one(): void
    {
        $this->actingAs($this->staff())
            ->postJson('/api/admin/pages', [
                'slug' => 'Not A Slug', 'title' => 'x', 'body' => '<p>x</p>',
            ])->assertStatus(422);
    }

    public function test_pages_belong_to_marketing(): void
    {
        $this->seedPages();

        $this->actingAs($this->staff(Roles::MANAGER))->get('/admin/pages')->assertStatus(200);

        $keeper = $this->staff(Roles::STOREKEEPER);
        $this->actingAs($keeper)->get('/admin/pages')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($keeper)
            ->postJson('/api/admin/pages', ['slug' => 'x', 'title' => 'x', 'body' => '<p>x</p>'])
            ->assertStatus(403);
    }

    /** Re-running the seeder must not overwrite what the shop has written. */
    public function test_seeding_twice_keeps_the_shops_own_words(): void
    {
        $this->seedPages();
        ContentPage::where('slug', 'terms')->update(['body' => '<p>Our own terms.</p>']);

        $this->seedPages();

        $this->assertStringContainsString(
            'Our own terms.',
            ContentPage::where('slug', 'terms')->value('body')
        );
        $this->assertSame(4, ContentPage::count());
    }
}
