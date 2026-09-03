<?php

namespace Tests\Feature\Storefront;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The campaigns the shop runs.
 *
 * `/offers` used to render the product listing with `onSaleOnly` — every
 * product whose price is cut. That is a different thing wearing the same word:
 * it is derived from the catalogue and nobody writes it. It moved to
 * /discounts, and this took its place.
 */
class OffersTest extends TestCase
{
    use RefreshDatabase;

    private function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'title' => 'Desktop Dhamaka',
            'slug' => 'desktop-dhamaka-'.uniqid(),
            'excerpt' => 'Buy a desktop, get a gift.',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addWeek(),
            'availability' => 'All outlets',
            'is_active' => true,
        ], $overrides));
    }

    public function test_the_offers_page_lists_what_is_running(): void
    {
        $running = $this->offer(['title' => 'Running now']);

        $this->getJson('/api/offers')
            ->assertOk()
            ->assertJsonPath('data.0.title', $running->title);
    }

    /* Announced but not begun still belongs on the page — that is the point
       of announcing it. */
    public function test_an_upcoming_offer_is_listed_and_says_so(): void
    {
        $this->offer([
            'title' => 'Branch opening',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeeks(2),
        ]);

        $this->getJson('/api/offers')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Branch opening')
            ->assertJsonPath('data.0.status', Offer::STATUS_UPCOMING);
    }

    public function test_a_finished_offer_drops_off_the_list(): void
    {
        $this->offer([
            'title' => 'Last month',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);

        $this->getJson('/api/offers')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_one_switched_off_is_not_shown_even_within_its_dates(): void
    {
        $this->offer(['title' => 'Not ready', 'is_active' => false]);

        $this->getJson('/api/offers')->assertJsonCount(0, 'data');
    }

    /*
     * A link sent to a customer last week should still explain what the offer
     * was, rather than turning into a 404 the moment it ends.
     */
    public function test_a_finished_offer_keeps_its_page(): void
    {
        $ended = $this->offer([
            'slug' => 'ended-offer',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);

        $this->getJson('/api/offers/'.$ended->slug)
            ->assertOk()
            ->assertJsonPath('data.status', Offer::STATUS_ENDED);
    }

    public function test_an_offer_switched_off_is_gone_entirely(): void
    {
        $off = $this->offer(['slug' => 'switched-off', 'is_active' => false]);

        $this->getJson('/api/offers/'.$off->slug)->assertNotFound();
    }

    public function test_an_unknown_slug_is_a_404_not_an_empty_page(): void
    {
        $this->getJson('/api/offers/no-such-offer')->assertNotFound();
    }

    /** An offer with no window is a standing one, not a broken one. */
    public function test_an_offer_with_no_dates_is_running(): void
    {
        $this->offer(['starts_at' => null, 'ends_at' => null]);

        $this->getJson('/api/offers')
            ->assertJsonPath('data.0.status', Offer::STATUS_RUNNING);
    }

    /* The terms are rendered as raw HTML on the offer page, so what is stored
       has to be safe — and the cleaning must not depend on which controller
       happened to write it. */
    public function test_the_terms_are_cleaned_before_they_are_stored(): void
    {
        $offer = $this->offer([
            'content' => '<p>Real terms</p><script>alert(1)</script>',
        ]);

        $this->assertStringContainsString('Real terms', $offer->content);
        $this->assertStringNotContainsString('<script', $offer->content);
    }

    /*
     * An offer's terms are usually a table — buy this, get that. The sanitiser
     * has always allowed one; this is the check that it still does, because a
     * tightened allowlist would silently flatten every gift matrix in the shop
     * into a run-on paragraph.
     */
    public function test_the_terms_may_carry_a_table_and_a_list(): void
    {
        $offer = $this->offer([
            'content' => '<h4>Gift details</h4>'
                .'<table><thead><tr><th>Range</th><th>Gift</th></tr></thead>'
                .'<tbody><tr><td>Ryzen 5</td><td>Earbuds</td></tr></tbody></table>'
                .'<ul><li>One gift per purchase.</li></ul>',
        ]);

        $this->assertStringContainsString('<table', $offer->content);
        $this->assertStringContainsString('<th', $offer->content);
        $this->assertStringContainsString('Earbuds', $offer->content);
        $this->assertStringContainsString('<li', $offer->content);
    }

    /*
     * The header's Offers button says "RUNNING NOW" on every page of the shop.
     * With nothing running that is a claim the shop cannot keep, and following
     * it lands on an empty page — so the count it depends on has to be shared
     * with every page, and has to be right.
     */
    public function test_every_page_is_told_how_many_offers_are_running(): void
    {
        $this->offer(['title' => 'On now']);
        $this->offer([
            'title' => 'Not yet',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeeks(2),
        ]);
        $this->offer([
            'title' => 'Over',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
        $this->offer(['title' => 'Switched off', 'is_active' => false]);

        // Running and upcoming — the two the page shows. Not the finished one,
        // and not the one staff have switched off.
        $this->assertSame(
            2,
            $this->get('/')->viewData('page')['props']['offers_running']
        );
    }

    public function test_the_count_is_nought_when_nothing_is_on(): void
    {
        $this->offer(['title' => 'Switched off', 'is_active' => false]);

        $this->assertSame(
            0,
            $this->get('/')->viewData('page')['props']['offers_running']
        );
    }

    public function test_both_pages_render(): void
    {
        // The campaigns, and the discounted listing at its new address.
        $this->get('/offers')->assertOk();
        $this->get('/discounts')->assertOk();
        $this->get('/offers/'.$this->offer(['slug' => 'a-page'])->slug)->assertOk();
    }

    // --- the manager -----------------------------------------------------

    private function staff(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_staff_can_create_an_offer(): void
    {
        $this->actingAs($this->staff())
            ->postJson('/api/admin/offers', [
                'title' => 'Eid Bonanza',
                'excerpt' => 'Gifts with every laptop.',
                'starts_at' => now()->toDateTimeString(),
                'ends_at' => now()->addWeek()->toDateTimeString(),
                'availability' => 'All outlets',
                'is_active' => true,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('offers', [
            'title' => 'Eid Bonanza',
            'slug' => 'eid-bonanza',
        ]);
    }

    public function test_an_offer_cannot_end_before_it_starts(): void
    {
        $this->actingAs($this->staff())
            ->postJson('/api/admin/offers', [
                'title' => 'Backwards',
                'starts_at' => now()->addWeek()->toDateTimeString(),
                'ends_at' => now()->toDateTimeString(),
            ])
            // The app wraps validation errors in its own envelope rather
            // than Laravel's top-level `errors`, so assertJsonValidationErrors
            // looks in the wrong place.
            ->assertStatus(422)
            ->assertJsonPath(
                'data.errors.ends_at.0',
                'The offer cannot end before it starts.'
            );
    }

    /*
     * A live offer's link is in emails and on posters. Renaming it must not
     * move the address out from under them.
     */
    public function test_renaming_a_live_offer_keeps_its_address(): void
    {
        $offer = $this->offer(['slug' => 'original-address', 'is_active' => true]);

        $this->actingAs($this->staff())
            ->putJson('/api/admin/offers/'.$offer->id, [
                'title' => 'A completely different name',
                'is_active' => true,
            ])
            ->assertOk();

        $this->assertSame('original-address', $offer->fresh()->slug);
    }

    public function test_renaming_a_draft_does_move_its_address(): void
    {
        $offer = $this->offer(['slug' => 'draft-address', 'is_active' => false]);

        $this->actingAs($this->staff())
            ->putJson('/api/admin/offers/'.$offer->id, [
                'title' => 'Its Real Name',
                'is_active' => false,
            ])
            ->assertOk();

        $this->assertSame('its-real-name', $offer->fresh()->slug);
    }

    public function test_a_customer_cannot_manage_offers(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)
            ->postJson('/api/admin/offers', ['title' => 'Mine now'])
            ->assertForbidden();

        $this->assertDatabaseCount('offers', 0);
    }
}
