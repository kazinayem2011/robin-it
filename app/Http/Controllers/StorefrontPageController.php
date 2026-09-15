<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\BlogPost;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CategorySlugHistory;
use App\Models\ContentPage;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\AddressBook;
use App\Services\ProductService;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The storefront's page shells.
 *
 * Each of these was a closure in routes/web.php doing the same thing with a
 * different component name. Gathering them here keeps the route file to a list
 * of URLs, and gives the pages somewhere to grow when one of them needs real
 * server-side data.
 */
class StorefrontPageController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    public function home(): Response
    {
        return Inertia::render('Welcome', [
            'banners' => Banner::active()->orderBy('sort_order')->get(),
            'blogs' => BlogPost::published()->orderBy('published_at', 'desc')->take(3)->get(),

            /*
             * The brand row, from the table rather than from a constant.
             *
             * It was fourteen names and fourteen file paths hardcoded in
             * BrandLogos.jsx, kept entirely separately from the brands an admin
             * manages — so uploading a logo changed the mega menu and could
             * never touch the homepage, and five of those files turned out to
             * be the wrong company's marks with nobody able to correct them
             * without a deploy.
             *
             * Which brands appear is the featured flag, ordered by name, so it
             * is curated in /admin/brands. A brand with no logo on file draws
             * its name instead of a broken image.
             */
            'brands' => Brand::featured()->get(['id', 'name', 'slug', 'logo_path']),
        ]);
    }

    /**
     * The shop listing.
     *
     * A legacy ?brand=<slug> is turned into the ?brand_ids=<id> the listing
     * actually filters on. The homepage's brand tiles and the search box's
     * brand pills both linked with the slug, and the page does not read it:
     * the request went through as an unmatched filter and came back 0 of 0 —
     * an empty shop, not an unfiltered one. Those links are corrected, but
     * anything already shared, bookmarked or indexed still carries the slug,
     * and a redirect is what makes those work rather than 404 quietly with a
     * page full of nothing.
     */
    public function shop(Request $request): Response|RedirectResponse
    {
        $slug = trim((string) $request->query('brand', ''));

        if ($slug !== '' && ! $request->has('brand_ids')) {
            $id = Brand::where('slug', $slug)->value('id');

            // An unknown brand drops the parameter rather than filtering on
            // nothing: the whole shop is a better answer than an empty one.
            $query = $request->query();
            unset($query['brand']);

            if ($id) {
                $query['brand_ids'] = (string) $id;
            }

            // Built by hand rather than with fullUrlWithQuery, which leaves a
            // bare "?" on the end when nothing survives — /shop? in the
            // address bar of everyone who follows a link to a brand the shop
            // no longer carries.
            return redirect()->to(
                $request->url().($query ? '?'.http_build_query($query) : '')
            );
        }

        return Inertia::render('Products/Index', [
            'seo' => Seo::for([
                'title' => 'Shop All Products',
                'description' => 'Buy at the best price in Bangladesh, with warranty and nationwide delivery.',
            ]),
        ]);
    }

    /**
     * A slug that names no category used to render the ordinary listing, which
     * then asked the API for that category and got nothing back: a 200 with an
     * empty grid, identical to a category that is genuinely out of stock. Five
     * footer links and a homepage promo had been pointing at slugs the
     * catalogue never had ("laptops" for "laptop") and nothing said so. Making
     * the miss a 404 is what turns the next such typo into something visible.
     */
    public function shopCategory(string $categorySlug): Response|RedirectResponse
    {
        $shelf = Category::where('slug', $categorySlug)
            ->where('is_active', true)
            ->first(['id', 'name']);

        if ($shelf) {
            return Inertia::render('Products/Index', [
                'categorySlug' => $categorySlug,
                'seo' => Seo::for([
                    'title' => $shelf->name,
                    'description' => "Buy {$shelf->name} at the best price in Bangladesh.",
                ]),
            ]);
        }

        /*
         * An address the shelf used to answer at. The live lookup goes first,
         * so a slug that has since been taken by another category belongs to
         * whoever holds it now, not to the history.
         *
         * Permanent, so a search engine moves its index rather than keeping
         * both and splitting the page's standing between them.
         */
        $moved = CategorySlugHistory::where('slug', $categorySlug)
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->with('category:id,slug')
            ->first();

        if ($moved) {
            return redirect()->route('shop.category', $moved->category->slug, 301);
        }

        abort(404);
    }

    public function product(string $slug): Response
    {
        /*
         * Looked up here as well as on the client. The page's own data still
         * arrives from the API, but a share card and a search result are read
         * out of the HTML by machines that run no JavaScript, and until this
         * every product answered them with the shop's name and nothing else.
         */
        $product = Product::where('slug', $slug)
            ->where('is_active', true)
            ->with(['images:id,product_id,image_path', 'brand:id,name'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->first();

        return Inertia::render('Products/Show', [
            'productSlug' => $slug,
            'seo' => $product ? Seo::forProduct($product) : Seo::for(),
        ]);
    }

    /**
     * The shop listing restricted to discounted stock, rather than a second
     * listing that would have to grow its own paging, filters and URL sync.
     */
    /**
     * The campaigns the shop is running.
     *
     * This route used to render the discounted listing, which is a different
     * thing wearing the same word: that is worked out from product prices and
     * nobody writes it, while an offer is announced, has a window, applies at
     * named outlets and has terms worth a page. It moved to /discounts.
     */
    public function offers(): Response
    {
        return Inertia::render('Offers/Index', [
            'seo' => Seo::for([
                'title' => 'Offers',
                'description' => 'Running offers, what they apply to and when they end.',
            ]),
        ]);
    }

    public function offer(string $slug): Response
    {
        return Inertia::render('Offers/Show', [
            'slug' => $slug,
            'seo' => Seo::for(['title' => 'Offer']),
        ]);
    }

    /** Every product whose price is cut. */
    public function discounts(): Response
    {
        return Inertia::render('Products/Index', [
            'onSaleOnly' => true,
            'seo' => Seo::for([
                'title' => 'Discounts',
                'description' => 'Every product in the shop whose price is cut right now.',
            ]),
        ]);
    }

    public function cart(): Response
    {
        return Inertia::render('Checkout/Cart', ['seo' => Seo::for(['title' => 'Your Cart', 'noindex' => true])]);
    }

    public function checkout(): Response
    {
        // A signed-in customer has told us where they live, sometimes several
        // times over. Handing them five empty boxes asks them to say it again.
        return Inertia::render('Checkout/Index', AddressBook::forCheckout(Auth::user())
            + ['seo' => Seo::for(['title' => 'Checkout', 'noindex' => true])]);
    }

    public function orderSuccess(Request $request): Response
    {
        $number = $request->query('order');

        /*
         * What else they might want, worked out from what they just bought.
         *
         * Server-side rather than a fetch: this page is often the last one
         * somebody sees, and a row that arrives after they have gone is a row
         * nobody saw. Falls back to what is popular when the order cannot be
         * found, which is the case for anyone who lands here with a stale link.
         */
        $bought = $number
            ? Order::where('order_number', $number)->first()?->items->pluck('product_id')->all()
            : [];

        $suggestions = $this->products->similarToCart($bought ?? []);

        if ($suggestions->isEmpty()) {
            $suggestions = $this->products->getFeaturedProducts('all', 4);
        }

        return Inertia::render('Checkout/Success', [
            'orderNumber' => $number,
            'suggestions' => $suggestions->values(),
            'seo' => Seo::for(['title' => 'Order Placed', 'noindex' => true]),
        ]);
    }

    public function pcBuilder(): Response
    {
        return Inertia::render('PcBuilder/Index', [
            'seo' => Seo::for([
                'title' => 'PC Builder',
                'description' => 'Pick your parts and we check they fit together before you buy.',
            ]),
        ]);
    }

    public function pcBuilderChoose(string $categorySlug): Response
    {
        return Inertia::render('PcBuilder/SelectComponent', [
            'categorySlug' => $categorySlug,
            'seo' => Seo::for(['title' => 'Choose a part', 'noindex' => true]),
        ]);
    }

    /**
     * @param  string|null  $orderNumber  from /track/{orderNumber}, which fills
     *                                    in the first box and nothing more —
     *                                    the phone number is still what proves
     *                                    the order is yours
     */
    public function track(?string $orderNumber = null): Response
    {
        return Inertia::render('Track/Index', [
            'orderNumber' => $orderNumber ? (Order::normalizeNumber($orderNumber) ?: null) : null,
            'seo' => Seo::for(['title' => 'Track Your Order', 'noindex' => true]),
        ]);
    }

    public function wishlist(): Response
    {
        return Inertia::render('Wishlist/Index', ['seo' => Seo::for(['title' => 'Wishlist', 'noindex' => true])]);
    }

    public function compare(): Response
    {
        return Inertia::render('Compare/Index', ['seo' => Seo::for(['title' => 'Compare', 'noindex' => true])]);
    }

    public function stores(): Response
    {
        return Inertia::render('Stores/Index', [
            'seo' => Seo::for([
                'title' => 'Showrooms',
                'description' => 'Where to find us: addresses, phone numbers and opening hours.',
            ]),
        ]);
    }

    public function support(): Response
    {
        return Inertia::render('Support/Index', [
            'seo' => Seo::for([
                'title' => 'Support',
                'description' => 'Warranty claims, repairs and anything else you need a hand with.',
            ]),
        ]);
    }

    /**
     * Who the shop is.
     *
     * The footer has linked here since the site was built and it was a 404.
     * The numbers come from what the shop actually has rather than being
     * written into the page, so they stay true as it grows.
     */
    public function about(): Response
    {
        $about = ContentPage::published()->where('slug', 'about')->first();

        return Inertia::render('About/Index', [
            // The words are the shop's, kept in the database; the figures and
            // the showrooms are counted, so they cannot go stale.
            'page' => $about?->only(['title', 'subtitle', 'body', 'meta_description']),

            'seo' => Seo::for([
                'title' => 'About Us',
                'description' => $about?->meta_description ?: null,
            ]),
            'stats' => [
                'products' => Product::where('is_active', true)->count(),
                'brands' => Brand::count(),
                'showrooms' => Store::where('is_active', true)->count(),
                'customers' => User::where('role', User::ROLE_CUSTOMER)->count(),
            ],
            'showrooms' => Store::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'address', 'city', 'phone']),
        ]);
    }

    /**
     * One page for anything the shop writes itself.
     *
     * privacy, terms and the return policy were links in the footer with
     * nothing behind them.
     */
    public function page(string $slug): Response
    {
        $page = ContentPage::published()->where('slug', $slug)->firstOrFail();

        return Inertia::render('Page/Index', [
            'page' => $page->only([
                'slug', 'title', 'subtitle', 'body', 'meta_title', 'meta_description',
            ]),
            'updatedAt' => $page->updated_at?->format('j F Y'),

            'seo' => Seo::for([
                'title' => $page->meta_title ?: $page->title,
                'description' => $page->meta_description
                    ?: strip_tags((string) ($page->subtitle ?: $page->body)),
            ]),
        ]);
    }

    public function contact(): Response
    {
        return Inertia::render('Contact/Index', [
            'showrooms' => Store::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'address', 'city', 'phone']),
            // Signed in, there is no reason to ask for what we already know.
            'page' => ContentPage::published()->where('slug', 'contact')->first()
                ?->only(['title', 'subtitle', 'body']),
            'contact' => Auth::user() ? [
                'name' => Auth::user()->name,
                'email' => Auth::user()->email,
                'phone' => Auth::user()->phone,
            ] : null,

            'seo' => Seo::for([
                'title' => 'Contact Us',
                'description' => 'Phone, email and the address of every showroom.',
            ]),
        ]);
    }

    public function warranty(): Response
    {
        return Inertia::render('Warranty/Index', [
            'seo' => Seo::for([
                'title' => 'Warranty',
                'description' => 'What is covered, for how long, and how to make a claim.',
            ]),
        ]);
    }

    /**
     * The journal's filter tabs come from the posts rather than a list in the
     * page.
     *
     * Five of the six tabs were written into Blogs/Index.jsx — "Hardware
     * Review", "Industry News", "Benchmark & Overclocking", "PC Building
     * Guide" — and the posts are filed under none of them. Four of the five
     * therefore returned nothing, and the two categories that do have posts
     * ("Displays & Peripherals", "Storage & Memory") had no tab to reach them
     * by. Whoever files the next post picks the category, so the tabs have to
     * be read off the posts, not agreed with them by hand.
     */
    public function blogs(): Response
    {
        return Inertia::render('Blogs/Index', [
            'categories' => BlogPost::published()
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->select('category')
                ->groupBy('category')
                ->orderByRaw('COUNT(*) DESC')
                ->pluck('category')
                ->map(fn (string $c) => ['key' => $c, 'label' => self::titleCase($c)])
                ->values(),

            'seo' => Seo::for([
                'title' => 'Journal',
                'description' => 'Build guides, buying advice and what is new in the shop.',
            ]),
        ]);
    }

    /**
     * Categories are filed in caps ("PC BUILDING"), which is shouting in a tab
     * strip. Str::title alone would make that "Pc Building", so the acronyms a
     * computer shop files under keep their case.
     */
    private static function titleCase(string $value): string
    {
        $acronyms = ['PC', 'CPU', 'GPU', 'RAM', 'SSD', 'HDD', 'PSU', 'UPS', 'AI',
            'VR', 'TV', 'OLED', 'LED', 'LCD', 'USB', 'RGB', 'NVME', '4K', '8K'];

        return collect(explode(' ', Str::title($value)))
            ->map(fn (string $word) => in_array(strtoupper($word), $acronyms, true)
                ? (strtoupper($word) === 'NVME' ? 'NVMe' : strtoupper($word))
                : $word)
            ->implode(' ');
    }

    public function blog(string $slug): Response
    {
        $post = BlogPost::where('slug', $slug)->where('is_published', true)->first();

        return Inertia::render('Blogs/Show', [
            'slug' => $slug,
            'seo' => $post
                ? Seo::for([
                    'title' => $post->meta_title ?: $post->title,
                    'description' => $post->meta_description
                        ?: strip_tags((string) ($post->excerpt ?: $post->content)),
                    'image' => $post->featured_image ?: null,
                    'type' => 'article',
                ])
                : Seo::for(),
        ]);
    }
}
