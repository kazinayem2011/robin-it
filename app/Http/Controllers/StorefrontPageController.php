<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\BlogPost;
use App\Models\Order;
use App\Services\AddressBook;
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
        return Inertia::render('Checkout/Success', ['orderNumber' => $request->query('order')]);
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
