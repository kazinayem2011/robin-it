<?php

use App\Constants\ApiEndpoints;
use App\Http\Controllers\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Admin\BlogController as AdminBlogController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ExpenseController as AdminExpenseController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\ShowroomController as AdminShowroomController;
use App\Http\Controllers\Admin\StockController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\WarrantyController as AdminWarrantyController;
use App\Http\Controllers\Customer\AvatarController;
use App\Http\Controllers\Customer\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StorefrontPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront pages
|--------------------------------------------------------------------------
| Every one of these renders an Inertia page and nothing else. They used to be
| a wall of closures doing the same thing with a different component name; the
| controller keeps that in one place and gives them somewhere to grow.
*/
Route::controller(StorefrontPageController::class)->group(function () {
    Route::get(ApiEndpoints::WEB_HOME, 'home')->name('home');

    Route::get(ApiEndpoints::WEB_SHOP, 'shop')->name('shop.index');
    Route::get(ApiEndpoints::WEB_SHOP_CATEGORY, 'shopCategory')->name('shop.category');
    Route::get(ApiEndpoints::WEB_PRODUCTS, 'shop')->name('products.index');
    Route::get(ApiEndpoints::WEB_PRODUCT_SHOW, 'product')->name('products.show');

    /*
     * Offers & promos: the shop listing restricted to discounted stock. This
     * rendered 'Offers/Index', a page component that was never written, so
     * every visit — including the header's Offers button, on every page — was
     * a 500.
     */
    Route::get(ApiEndpoints::WEB_OFFERS, 'offers')->name('offers');

    Route::get(ApiEndpoints::WEB_CART, 'cart')->name('cart');
    Route::get(ApiEndpoints::WEB_CHECKOUT, 'checkout')->name('checkout');
    Route::get(ApiEndpoints::WEB_ORDER_SUCCESS, 'orderSuccess')->name('order.success');

    Route::get(ApiEndpoints::WEB_PC_BUILDER, 'pcBuilder')->name('pc-builder');
    Route::get(ApiEndpoints::WEB_PC_BUILDER_CHOOSE, 'pcBuilderChoose')->name('pc-builder.choose');

    Route::get(ApiEndpoints::WEB_TRACK, 'track')->name('track');
    Route::get(ApiEndpoints::WEB_TRACK_ORDER, 'track');

    Route::get(ApiEndpoints::WEB_WISHLIST, 'wishlist')->name('wishlist');
    Route::get(ApiEndpoints::WEB_COMPARE, 'compare')->name('compare');
    Route::get(ApiEndpoints::WEB_STORES, 'stores')->name('stores');
    Route::get(ApiEndpoints::WEB_SUPPORT, 'support')->name('support');
    Route::get(ApiEndpoints::WEB_WARRANTY, 'warranty')->name('warranty');

    Route::get(ApiEndpoints::WEB_BLOGS, 'blogs')->name('blogs.index');
    Route::get(ApiEndpoints::WEB_BLOG_SHOW, 'blog')->name('blogs.show');
});

/*
|--------------------------------------------------------------------------
| Authenticated customer account
|--------------------------------------------------------------------------
| These are posted with Inertia's router, not axios, so they live here and
| answer with a redirect and a flash message.
*/
Route::middleware(['auth'])->group(function () {
    Route::get(ApiEndpoints::DASHBOARD, [DashboardController::class, 'index'])->name('dashboard');
    Route::get(ApiEndpoints::ACCOUNT, [DashboardController::class, 'index'])->name('account');

    // One page per section. Each loads only its own data: the single-page
    // version fetched every order with its items, every address and every
    // wishlist product on every visit, whichever tab you were looking at.
    Route::get(ApiEndpoints::DASHBOARD_ORDERS, [DashboardController::class, 'orders'])->name('dashboard.orders');
    Route::get(ApiEndpoints::DASHBOARD_WISHLIST, [DashboardController::class, 'wishlist'])->name('dashboard.wishlist');
    Route::get(ApiEndpoints::DASHBOARD_ADDRESSES, [DashboardController::class, 'addresses'])->name('dashboard.addresses');
    Route::get(ApiEndpoints::DASHBOARD_PROFILE, [DashboardController::class, 'profile'])->name('dashboard.profile');

    Route::post(ApiEndpoints::ACCOUNT_PROFILE, [DashboardController::class, 'updateProfile'])->name('account.profile');
    Route::post(ApiEndpoints::ACCOUNT_AVATAR, [AvatarController::class, 'store'])->name('account.avatar');
    Route::delete(ApiEndpoints::ACCOUNT_AVATAR, [AvatarController::class, 'destroy'])->name('account.avatar.delete');
    Route::post(ApiEndpoints::ACCOUNT_ADDRESS, [DashboardController::class, 'saveAddress'])->name('account.address');
    Route::delete(ApiEndpoints::ACCOUNT_ADDRESS_ITEM, [DashboardController::class, 'deleteAddress'])->name('account.address.delete');
    Route::put(ApiEndpoints::ACCOUNT_PASSWORD, [DashboardController::class, 'updatePassword'])->name('account.password');
    Route::post(ApiEndpoints::ACCOUNT_ORDER_CANCEL, [DashboardController::class, 'cancelOrder'])->name('account.orders.cancel');

    Route::get(ApiEndpoints::WEB_PROFILE, [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch(ApiEndpoints::WEB_PROFILE, [ProfileController::class, 'update'])->name('profile.update');
    Route::delete(ApiEndpoints::WEB_PROFILE, [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Admin screens
|--------------------------------------------------------------------------
| Page renders only. Every admin write is an axios call to /api/admin/* (see
| routes/api.php) — each one used to be declared here as well, pointing at the
| same controller method, which is why those methods each carried a branch
| asking whether to answer with JSON or a redirect.
*/
Route::middleware(['auth', 'admin'])
    ->prefix(ApiEndpoints::ADMIN_PREFIX)
    ->name('admin.')
    ->group(function () {
        // Typing the bare /admin used to 404 — nothing links to it, but it is
        // the URL an admin reaches for.
        Route::redirect('/', ApiEndpoints::ADMIN_PREFIX.'/'.ApiEndpoints::ADMIN_DASHBOARD);

        Route::get(ApiEndpoints::ADMIN_DASHBOARD, [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get(ApiEndpoints::ADMIN_ORDERS, [AdminOrderController::class, 'index'])->name('orders');
        Route::get(ApiEndpoints::ADMIN_PRODUCTS, [AdminProductController::class, 'index'])->name('products');
        Route::get(ApiEndpoints::ADMIN_CATEGORIES, [AdminCategoryController::class, 'index'])->name('categories');
        Route::get(ApiEndpoints::ADMIN_BANNERS, [AdminBannerController::class, 'index'])->name('banners');
        Route::get(ApiEndpoints::ADMIN_COUPONS, [AdminCouponController::class, 'index'])->name('coupons');
        Route::get(ApiEndpoints::ADMIN_STORES, [AdminShowroomController::class, 'index'])->name('stores');
        Route::get(ApiEndpoints::ADMIN_SETTINGS, [AdminSettingController::class, 'index'])->name('settings');
        Route::get(ApiEndpoints::ADMIN_CUSTOMERS, [AdminCustomerController::class, 'index'])->name('customers');
        Route::get(ApiEndpoints::ADMIN_EXPENSES, [AdminExpenseController::class, 'index'])->name('expenses');
        Route::get(ApiEndpoints::ADMIN_REPORTS_PROFIT, [AdminReportController::class, 'profitAndLoss'])->name('reports.profit');
        Route::get(ApiEndpoints::ADMIN_BLOGS, [AdminBlogController::class, 'index'])->name('blogs');
        Route::get(ApiEndpoints::ADMIN_REVIEWS, [AdminReviewController::class, 'index'])->name('reviews');
        Route::get(ApiEndpoints::ADMIN_WARRANTY, [AdminWarrantyController::class, 'index'])->name('warranty');

        // Inventory & suppliers, each in their own section.
        Route::get('stock', [StockController::class, 'index'])->name('stock');
        Route::get(ApiEndpoints::ADMIN_SUPPLIERS, [SupplierController::class, 'index'])->name('suppliers');
    });

/*
 * Printable invoice. Outside the admin group because a customer prints their
 * own; the controller decides who may see which.
 */
Route::get(ApiEndpoints::ORDER_INVOICE, [InvoiceController::class, 'show'])->name('orders.invoice');

/*
 * Sitemap and robots.txt, generated rather than static: the catalogue changes
 * daily, and a stale sitemap points crawlers at products that no longer exist.
 * The static public/robots.txt is removed in favour of this.
 */
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');

require __DIR__.'/auth.php';
