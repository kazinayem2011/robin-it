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

    /**
     * The Product markup a search engine reads, settled on the server.
     *
     * This is what puts a price, availability and a star rating in a search
     * result rather than a bare blue link. The page builds the same thing in
     * JavaScript, and Google reaches that on a second pass — but only Google,
     * and only eventually.
     *
     * The rules are the ones productSchema.js already worked out the hard way.
     * `checkout_price` rather than `effective_price`, because the markup is
     * rejected when it disagrees with the price the page headlines. Anything
     * the shop has not recorded is left out rather than guessed at: a brand or
     * a part number invented here is published straight into a search result.
     * And a rating only with reviews behind it — the page falls back to five
     * stars when a product has none, and an invented rating is the one thing
     * that gets a shop's results suppressed rather than merely ignored.
     *
     * @return array<string, mixed>
     */
    public static function productSchema(Product $product): array
    {
        $availability = $product->in_stock
            ? 'InStock'
            : ($product->allow_preorder ? 'PreOrder' : 'OutOfStock');

        $reviewCount = (int) ($product->reviews_count ?? 0);

        return array_filter([
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $product->name,
            'image' => self::absolute($product->images->first()->image_path ?? null),
            'description' => $product->short_description ?: $product->name,
            'brand' => $product->brand
                ? ['@type' => 'Brand', 'name' => $product->brand->name]
                : null,
            'mpn' => $product->mpn ?: null,
            'model' => $product->model ?: null,
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'BDT',
                'price' => $product->checkout_price,
                'itemCondition' => 'https://schema.org/NewCondition',
                'availability' => "https://schema.org/{$availability}",
                'seller' => ['@type' => 'Organization', 'name' => BrandDetails::name()],
            ],
            'aggregateRating' => $reviewCount > 0
                ? [
                    '@type' => 'AggregateRating',
                    'ratingValue' => round((float) $product->reviews_avg_rating, 1),
                    'reviewCount' => $reviewCount,
                ]
                : null,
        ], fn ($value) => $value !== null);
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
            'schema' => self::productSchema($product),
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
