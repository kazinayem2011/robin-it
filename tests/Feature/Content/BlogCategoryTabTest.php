<?php

namespace Tests\Feature\Content;

use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The journal's filter tabs come from the posts.
 *
 * They used to be a list typed into Blogs/Index.jsx — "Hardware Review",
 * "Industry News", "Benchmark & Overclocking", "PC Building Guide" — and no
 * post was filed under any of them. Four of the five tabs opened on an empty
 * page, and the categories that do have posts had no tab to reach them by.
 * Like the footer's category links, it was a list agreed with the data by hand
 * and then left to drift.
 */
class BlogCategoryTabTest extends TestCase
{
    use RefreshDatabase;

    private function publish(string $category, string $slug): BlogPost
    {
        return BlogPost::create([
            'title' => ucfirst($slug),
            'slug' => $slug,
            'category' => $category,
            'excerpt' => 'x',
            'image_path' => 'blog/x.jpg',
            'content' => 'x',
            'is_published' => true,
            'published_at' => now()->subDay(),
        ]);
    }

    public function test_the_tabs_are_the_categories_posts_are_filed_under(): void
    {
        $this->publish('BUYING GUIDE', 'a');
        $this->publish('BUYING GUIDE', 'b');
        $this->publish('STORAGE & MEMORY', 'c');

        $categories = $this->get('/blogs')
            ->assertOk()
            ->viewData('page')['props']['categories'];

        $this->assertSame(
            ['BUYING GUIDE', 'STORAGE & MEMORY'],
            collect($categories)->pluck('key')->all(),
            'Tabs should be the categories in use, busiest first.'
        );
    }

    public function test_every_tab_returns_the_posts_it_names(): void
    {
        $this->publish('BUYING GUIDE', 'a');
        $this->publish('STORAGE & MEMORY', 'c');

        $categories = $this->get('/blogs')->viewData('page')['props']['categories'];

        foreach ($categories as $category) {
            $body = $this->getJson('/api/blogs?category='.urlencode($category['key']))
                ->assertOk()
                ->json('data');

            $this->assertNotEmpty(
                $body,
                "The \"{$category['label']}\" tab returned no posts."
            );
        }
    }

    public function test_a_category_with_no_published_post_gets_no_tab(): void
    {
        $this->publish('BUYING GUIDE', 'a');
        $this->publish('DRAFT ONLY', 'b')->update(['is_published' => false]);

        $keys = collect($this->get('/blogs')->viewData('page')['props']['categories'])->pluck('key');

        $this->assertContains('BUYING GUIDE', $keys);
        $this->assertNotContains('DRAFT ONLY', $keys);
    }

    public function test_the_acronyms_a_computer_shop_files_under_keep_their_case(): void
    {
        $this->publish('PC BUILDING', 'a');

        $labels = collect($this->get('/blogs')->viewData('page')['props']['categories'])->pluck('label');

        // Str::title alone gives "Pc Building".
        $this->assertContains('PC Building', $labels);
    }
}
