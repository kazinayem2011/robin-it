<?php

namespace Tests\Feature\Content;

use Database\Seeders\ContentPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three pages a shop is judged on when something goes wrong.
 *
 * They shipped carrying a paragraph asking the reader to review and adjust the
 * text — a note addressed to the shop, printed to the customer, on the page
 * that is supposed to say what the shop's word is worth.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['privacy', 'terms', 'return-policy'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentPageSeeder::class);
    }

    /**
     * The specific words that were showing, and any obvious relative of them.
     * A placeholder that reaches a customer is worse than no page at all.
     */
    public function test_no_page_asks_the_customer_to_write_it(): void
    {
        $tells = [
            'adjust it to match',
            'lorem ipsum',
            'your shop actually operates',
            'TODO',
            'placeholder',
            'sample text',
        ];

        foreach (self::PAGES as $slug) {
            $body = $this->get("/{$slug}")->assertOk()
                ->viewData('page')['props']['page']['body'];

            foreach ($tells as $tell) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $tell,
                    $body,
                    "The {$slug} page still reads like a draft."
                );
            }
        }
    }

    /** A page short enough to say nothing is a page that says nothing. */
    public function test_each_page_says_something(): void
    {
        foreach (self::PAGES as $slug) {
            $body = $this->get("/{$slug}")->viewData('page')['props']['page']['body'];

            $this->assertGreaterThan(
                1500,
                strlen($body),
                "The {$slug} page is too short to cover what it claims to."
            );
            $this->assertGreaterThanOrEqual(
                4,
                substr_count($body, '<h2>'),
                "The {$slug} page needs sections a customer can find an answer in."
            );
        }
    }

    /**
     * Each page has to cover the things this shop actually does, or it is
     * generic text that happens to be on our domain.
     */
    public function test_each_page_covers_what_this_shop_does(): void
    {
        $mustMention = [
            // Codes by SMS, the courier handover, and the serial-backed warranty
            // are all things this system does to a customer's data.
            'privacy' => ['code', 'courier', 'serial', 'cookie'],
            // Deposits, third-party delivery, warranty exclusions, and the law.
            'terms' => ['deposit', 'courier', 'warranty', 'Bangladesh'],
            // The three ways a return happens, and how the money gets back.
            'return-policy' => ['7 days', '72 hours', 'refund', 'serial'],
        ];

        foreach ($mustMention as $slug => $terms) {
            $body = $this->get("/{$slug}")->viewData('page')['props']['page']['body'];

            foreach ($terms as $term) {
                $this->assertStringContainsStringIgnoringCase(
                    $term,
                    $body,
                    "The {$slug} page never mentions {$term}."
                );
            }
        }
    }

    public function test_they_are_published_and_reachable(): void
    {
        foreach (self::PAGES as $slug) {
            $this->get("/{$slug}")->assertOk();
        }
    }
}
