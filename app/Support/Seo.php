<?php

namespace App\Support;

use App\Models\Product;
use App\Models\SiteSetting;
use Illuminate\Support\Str;

/**
 * The tags a crawler reads, worked out on the server.
 *
 * The shop is Inertia, so until now every one of these was written by React
 * after the bundle had loaded and run. Google gets there eventually, on a
 * second pass; Facebook, WhatsApp, LinkedIn and Twitter never do — they read
 * the HTML as delivered and stop. Every link anyone shared, of any product,
 * arrived with no title, no description and no picture, and every page in the
 * catalogue answered a search engine with the same four words: the shop's name.
 *
 * So the same tags are settled here, in the response itself, from whatever the
 * controller knows. `SEOHead` still runs on the client and still wins — the
 * tags below carry Inertia's `inertia` attribute, which is how its head manager
 * knows a tag is its to replace rather than duplicate — but a reader that never
 * runs a line of JavaScript now has something to read.
 */
class Seo
{
    /** Sensible when nothing else is known; a picture is better than none. */
    private const FALLBACK_IMAGE = '/images/hero_gaming_pc.png';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function for(array $overrides = []): array
    {
        $brand = BrandDetails::name();
        $tagline = SiteSetting::get('site_tagline') ?: 'The Store of Technology';

        $title = $overrides['title'] ?? null;

        return [
            /*
             * A page's own title, with the shop's name after it — unless the
             * page already ended with it, which several do, and which gave
             * "Robins Computer — The Store of Technology — Robins Computer".
             */
            'title' => $title
                ? self::withBrand($title, $brand)
                : (SiteSetting::get('meta_title') ?: "{$brand} | {$tagline}"),

            /*
             * `?:` rather than `??` all the way down: an unset setting comes
             * back as an empty string, not null, so `??` kept it and the tag
             * went out empty.
             */
            'description' => self::trim(
                ($overrides['description'] ?? null)
                    ?: (SiteSetting::get('meta_description')
                        ?: "{$brand} — {$tagline}"),
                160,
            ),

            'keywords' => ($overrides['keywords'] ?? null) ?: (SiteSetting::get('meta_keywords') ?: null),

            'image' => self::absolute(
                ($overrides['image'] ?? null)
                    ?: (SiteSetting::get('og_image') ?: self::FALLBACK_IMAGE)
            ),

            /*
             * Without the query string. `?page=2`, `?sort=price` and `?ref=fb`
             * are one page as far as indexing goes, and pointing each at itself
             * is the duplicate-content problem canonical exists to solve.
             */
            'canonical' => $overrides['canonical'] ?? url()->current(),

            'type' => $overrides['type'] ?? 'website',
            'noindex' => (bool) ($overrides['noindex'] ?? false),
            'site_name' => $brand,
            'verification' => SiteSetting::get('google_site_verification') ?: null,
            'schema' => $overrides['schema'] ?? null,
        ];
    }

    /** A product's own page: its name, its blurb, its photograph. */
    public static function forProduct(Product $product): array
    {
        $description = $product->meta_description
            ?: strip_tags((string) ($product->short_description ?: $product->description));

        return self::for([
            'title' => $product->meta_title ?: $product->name,
            'description' => $description ?: null,
            'keywords' => $product->meta_keyword ?: null,
            'image' => $product->images->first()->image_path ?? null,
            'type' => 'product',
        ]);
    }

    /**
     * Absolute, because a share card is fetched by a machine that has no idea
     * what site the path came from. A relative og:image is simply dropped.
     */
    private static function absolute(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Str::startsWith($path, ['http://', 'https://'])
            ? $path
            : url($path);
    }

    private static function withBrand(string $title, string $brand): string
    {
        return Str::endsWith(mb_strtolower($title), mb_strtolower($brand))
            ? $title
            : "{$title} | {$brand}";
    }

    /** Google shows about 160 characters; the rest is weight, not reading. */
    private static function trim(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return Str::limit($text, $limit, '…');
    }
}
