<?php

namespace Tests\Feature\Catalog;

use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An article's share card and search markup, as delivered in the HTML.
 *
 * The controller asked the post for `featured_image`, `meta_title` and
 * `meta_description`, none of which the table has, so every article shared on
 * Facebook, WhatsApp or LinkedIn arrived with the shop's generic card instead
 * of its own picture. These read the response a crawler reads, which runs no
 * JavaScript.
 */
class BlogShareCardTest extends TestCase
{
    use RefreshDatabase;

    private function article(array $attributes = []): BlogPost
    {
        return BlogPost::create($attributes + [
            'title' => 'PCIe Gen5 NVMe SSDs: An Upgrade Guide',
            'slug' => 'pcie-gen5-guide',
            'category' => 'STORAGE & MEMORY',
            'excerpt' => 'What 14,000 MB/s changes, and what it does not.',
            'content' => '<p>Body.</p>',
            'image_path' => '/images/og-default.jpg',
            'author_name' => 'Robin IT Hardware Lab',
            'is_published' => true,
            'published_at' => '2026-08-26 01:25:22',
        ]);
    }

    /** @return array<string, mixed> The BlogPosting markup in the page. */
    private function schema(string $html): array
    {
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match);

        return json_decode($match[1] ?? 'null', true) ?? [];
    }

    public function test_the_share_picture_is_the_articles_own(): void
    {
        $this->article(['image_path' => '/images/hero_banner_rog.jpg']);

        $this->get('/blogs/pcie-gen5-guide')
            ->assertOk()
            ->assertSee('property="og:image" content="'.url('/images/hero_banner_rog.jpg').'"', false)
            ->assertSee('name="twitter:image" content="'.url('/images/hero_banner_rog.jpg').'"', false);
    }

    public function test_it_is_shared_as_an_article_with_its_date_and_section(): void
    {
        $this->article();

        $this->get('/blogs/pcie-gen5-guide')
            ->assertSee('property="og:type" content="article"', false)
            ->assertSee('property="article:published_time" content="2026-08-26T01:25:22', false)
            ->assertSee('property="article:section" content="STORAGE &amp; MEMORY"', false)
            ->assertSee('property="article:author" content="Robin IT Hardware Lab"', false);
    }

    public function test_it_carries_blogposting_markup(): void
    {
        $this->article();

        $schema = $this->schema($this->get('/blogs/pcie-gen5-guide')->getContent());

        $this->assertSame('BlogPosting', $schema['@type'] ?? null);
        $this->assertSame('PCIe Gen5 NVMe SSDs: An Upgrade Guide', $schema['headline']);
        $this->assertStringStartsWith('2026-08-26T01:25:22', $schema['datePublished']);
        $this->assertSame(url('/images/og-default.jpg'), $schema['image']);
        $this->assertSame(['@type' => 'Person', 'name' => 'Robin IT Hardware Lab'], $schema['author']);
        $this->assertSame(url('/blogs/pcie-gen5-guide'), $schema['mainEntityOfPage']['@id']);
    }

    /** Written when the row was, not when the article went out. */
    public function test_the_date_is_the_publication_date_not_the_rows(): void
    {
        $article = $this->article();
        $article->forceFill(['created_at' => '2026-08-31 09:00:00'])->saveQuietly();

        $schema = $this->schema($this->get('/blogs/pcie-gen5-guide')->getContent());

        $this->assertStringStartsWith('2026-08-26', $schema['datePublished']);
    }

    /*
     * A title is typed by an admin and goes into a <script> block. Slashes
     * are left unescaped there, so a literal "</script>" would have ended the
     * block early and the rest of the title would have been read as HTML.
     */
    public function test_a_title_cannot_close_the_markup_early(): void
    {
        $this->article(['title' => 'Benchmarks </script><script>alert(1)</script>']);

        $html = $this->get('/blogs/pcie-gen5-guide')->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
        $this->assertSame(
            'Benchmarks </script><script>alert(1)</script>',
            $this->schema($html)['headline'],
        );
    }

    public function test_a_missing_article_still_has_the_shops_card(): void
    {
        $this->get('/blogs/no-such-article')
            ->assertOk()
            ->assertSee('property="og:image"', false)
            ->assertDontSee('article:published_time', false);
    }
}
