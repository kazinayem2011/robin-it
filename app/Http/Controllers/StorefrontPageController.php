<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\BlogPost;
use App\Models\Brand;
use App\Models\ContentPage;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\AddressBook;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        ]);
    }

    public function shop(): Response
    {
        return Inertia::render('Products/Index');
    }

    public function shopCategory(string $categorySlug): Response
    {
        return Inertia::render('Products/Index', ['categorySlug' => $categorySlug]);
    }

    public function product(string $slug): Response
    {
        return Inertia::render('Products/Show', ['productSlug' => $slug]);
    }

    /**
     * The shop listing restricted to discounted stock, rather than a second
     * listing that would have to grow its own paging, filters and URL sync.
     */
    public function offers(): Response
    {
        return Inertia::render('Products/Index', ['onSaleOnly' => true]);
    }

    public function cart(): Response
    {
        return Inertia::render('Checkout/Cart');
    }

    public function checkout(): Response
    {
        // A signed-in customer has told us where they live, sometimes several
        // times over. Handing them five empty boxes asks them to say it again.
        return Inertia::render('Checkout/Index', AddressBook::forCheckout(Auth::user()));
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
        ]);
    }

    public function pcBuilder(): Response
    {
        return Inertia::render('PcBuilder/Index');
    }

    public function pcBuilderChoose(string $categorySlug): Response
    {
        return Inertia::render('PcBuilder/SelectComponent', ['categorySlug' => $categorySlug]);
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
        ]);
    }

    public function wishlist(): Response
    {
        return Inertia::render('Wishlist/Index');
    }

    public function compare(): Response
    {
        return Inertia::render('Compare/Index');
    }

    public function stores(): Response
    {
        return Inertia::render('Stores/Index');
    }

    public function support(): Response
    {
        return Inertia::render('Support/Index');
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
        return Inertia::render('About/Index', [
            // The words are the shop's, kept in the database; the figures and
            // the showrooms are counted, so they cannot go stale.
            'page' => ContentPage::published()->where('slug', 'about')->first()
                ?->only(['title', 'subtitle', 'body', 'meta_description']),
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
        ]);
    }

    public function warranty(): Response
    {
        return Inertia::render('Warranty/Index');
    }

    public function blogs(): Response
    {
        return Inertia::render('Blogs/Index');
    }

    public function blog(string $slug): Response
    {
        return Inertia::render('Blogs/Show', ['slug' => $slug]);
    }
}
