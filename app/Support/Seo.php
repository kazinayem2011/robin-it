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
    /**
     * The shop's own card, for every page with no picture of its own.
     *
     * This pointed at /images/hero_gaming_pc.png, a file that has never been
     * in this repository — so the default og:image was a 404 and the default
     * share card had no picture, which is most of what was being reported.
     *
     * It is not one of the hero banners either. Every one of those is a
     * manufacturer's own advertisement, down to the product name set in their
     * type; a multi-brand retailer whose every shared link carries one
     * supplier's advert is advertising them rather than itself.
     */
    private const FALLBACK_IMAGE = '/images/og-default.jpg';

    /**
     * What a share crawler will actually draw.
     *
     * Facebook, WhatsApp and LinkedIn render this fixed set and show nothing
     * at all for anything outside it — SVG included, which happens to be the
     * only image 1,265 of this shop's 1,267 products carry.
     */
    private const DRAWABLE = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function for(array $overrides = []): array
    {
        /*
         * Already settled, so leave it alone.
         *
         * A controller builds this array with this very method and hands it to
         * the page as a prop; the layout then calls the method again on
         * whatever the page provided, because most pages provide nothing.
         * Passing a resolved array back through is harmless in every field but
         * one — by then `image` is an absolute URL rather than a path, which is
         * no longer a file this can measure, so every page that set its own
         * title silently lost its image dimensions.
         */
        if (isset($overrides['resolved'])) {
            return $overrides;
        }

        $brand = BrandDetails::name();
        $tagline = SiteSetting::get('site_tagline') ?: 'The Store of Technology';

        $title = $overrides['title'] ?? null;
        $image = self::shareImage($overrides['image'] ?? null);

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

            'image' => $image['url'],

            /*
             * Not required, and worth sending anyway: told the size up front,
             * a crawler lays out the large card on the first share instead of
             * waiting until it has fetched the picture to find out.
             */
            'image_width' => $image['width'],
            'image_height' => $image['height'],

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

            /* What the check at the top of this method reads. */
            'resolved' => true,
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
            /*
             * Through the same check as the share card. Google's Product
             * markup takes the same raster formats a share crawler does and
             * rejects an SVG, which is the only picture nearly every product
             * in this catalogue has — and rejected markup is no rich result
             * at all, where the shop's own card is at least valid.
             */
            'image' => self::shareImage($product->images->first()->image_path ?? null)['url'],
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
     * The picture on the share card, and its size.
     *
     * Two things have to be true of it and neither was checked. It has to be
     * a format the crawler can draw, or the card arrives with a blank where
     * the picture goes — and nearly every product in this catalogue carries
     * an SVG placeholder, which is exactly such a format. And it has to
     * exist, which the old fallback did not.
     *
     * @return array{url: string|null, width: int|null, height: int|null}
     */
    private static function shareImage(?string $candidate): array
    {
        $path = $candidate ?: (SiteSetting::get('og_image') ?: null);

        if (! $path || ! self::drawable($path)) {
            $path = self::FALLBACK_IMAGE;
        }

        return ['url' => self::absolute($path)] + self::measure($path);
    }

    private static function drawable(string $path): bool
    {
        $extension = mb_strtolower(pathinfo(
            parse_url($path, PHP_URL_PATH) ?: '',
            PATHINFO_EXTENSION,
        ));

        /*
         * A URL somebody typed into the settings screen, with nothing in it to
         * judge by. Their word is better than a guess here — the alternative
         * is quietly ignoring the picture the shop chose.
         */
        if ($extension === '' && Str::startsWith($path, ['http://', 'https://'])) {
            return true;
        }

        return in_array($extension, self::DRAWABLE, true);
    }

    /** Measured only when the file is one of ours; a remote one stays unstated. */
    private static function measure(string $path): array
    {
        $unknown = ['width' => null, 'height' => null];

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $unknown;
        }

        $file = public_path(ltrim((string) parse_url($path, PHP_URL_PATH), '/'));

        if (! is_file($file)) {
            return $unknown;
        }

        $size = @getimagesize($file);

        return $size ? ['width' => $size[0], 'height' => $size[1]] : $unknown;
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
