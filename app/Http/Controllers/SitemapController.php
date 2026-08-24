<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;

/**
 * The sitemap.
 *
 * Generated rather than a static file, because the catalogue changes daily and
 * a stale sitemap is worse than none — it points crawlers at products that have
 * been delisted and never mentions the ones just added.
 *
 * Only pages worth indexing are listed: nothing behind a login, nothing in the
 * checkout funnel, and no delisted product.
 */
class SitemapController extends Controller
{
    /** Long enough that a crawler is not re-fetching it on every hit. */
    private const CACHE_SECONDS = 3600;

    public function index(): Response
    {
        $base = rtrim(config('app.url'), '/');
        $urls = [];

        // Static pages, most important first.
        foreach ([
            ['/', '1.0', 'daily'],
            ['/products', '0.9', 'daily'],
            ['/pc-builder', '0.8', 'weekly'],
            ['/offers', '0.8', 'daily'],
            ['/stores', '0.6', 'monthly'],
            ['/blogs', '0.6', 'weekly'],
            ['/about', '0.4', 'monthly'],
            ['/contact', '0.4', 'monthly'],
            ['/warranty', '0.4', 'monthly'],
            ['/terms', '0.2', 'yearly'],
            ['/privacy', '0.2', 'yearly'],
        ] as [$path, $priority, $frequency]) {
            $urls[] = [$base.$path, null, $priority, $frequency];
        }

        Category::where('is_active', true)
            ->orderBy('id')
            ->chunk(200, function ($categories) use (&$urls, $base) {
                foreach ($categories as $category) {
                    $urls[] = [
                        $base.'/shop/'.$category->slug,
                        $category->updated_at,
                        '0.7',
                        'weekly',
                    ];
                }
            });

        Product::where('is_active', true)
            ->orderBy('id')
            ->chunk(200, function ($products) use (&$urls, $base) {
                foreach ($products as $product) {
                    $urls[] = [
                        $base.'/products/'.$product->slug,
                        $product->updated_at,
                        '0.8',
                        'weekly',
                    ];
                }
            });

        BlogPost::when(
            Schema::hasColumn('blog_posts', 'is_published'),
            fn ($q) => $q->where('is_published', true)
        )->orderBy('id')->chunk(200, function ($posts) use (&$urls, $base) {
            foreach ($posts as $post) {
                $urls[] = [$base.'/blogs/'.$post->slug, $post->updated_at, '0.5', 'monthly'];
            }
        });

        return response($this->render($urls), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
        ]);
    }

    /**
     * @param  array<int, array{0:string, 1:mixed, 2:string, 3:string}>  $urls
     */
    private function render(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as [$loc, $lastMod, $priority, $frequency]) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.htmlspecialchars($loc, ENT_XML1).'</loc>'."\n";

            if ($lastMod) {
                $xml .= '    <lastmod>'.$lastMod->toAtomString().'</lastmod>'."\n";
            }

            $xml .= "    <changefreq>{$frequency}</changefreq>\n";
            $xml .= "    <priority>{$priority}</priority>\n";
            $xml .= "  </url>\n";
        }

        return $xml.'</urlset>';
    }

    /**
     * robots.txt, served dynamically so the sitemap URL follows APP_URL rather
     * than being hardcoded into a file that nobody remembers to update.
     */
    public function robots(): Response
    {
        $base = rtrim(config('app.url'), '/');

        $disallow = [
            // Nothing behind a login, and nothing in the checkout funnel: a
            // crawler spends its budget there for no benefit, and an order
            // confirmation has no business in a search index.
            '/admin',
            '/account',
            '/dashboard',
            '/cart',
            '/checkout',
            '/orders',
            '/profile',
            '/login',
            '/register',
            '/password',
            '/compare',
            '/wishlist',
        ];

        $lines = ['User-agent: *'];

        foreach ($disallow as $path) {
            $lines[] = "Disallow: {$path}";
        }

        $lines[] = '';
        $lines[] = "Sitemap: {$base}/sitemap.xml";

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
