<?php

use App\Constants\ApiEndpoints;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\ComparisonController;
use App\Http\Controllers\Customer\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Models\Banner;
use App\Models\BlogPost;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public Homepage
Route::get(ApiEndpoints::WEB_HOME, function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'banners' => Banner::active()->orderBy('sort_order')->get(),
        'blogs' => BlogPost::published()->orderBy('published_at', 'desc')->take(3)->get(),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
})->name('home');

// Shop & Product Catalog Routes
Route::get(ApiEndpoints::WEB_SHOP, function () {
    return Inertia::render('Products/Index');
})->name('shop.index');

Route::get(ApiEndpoints::WEB_SHOP_CATEGORY, function ($categorySlug) {
    return Inertia::render('Products/Index', ['categorySlug' => $categorySlug]);
})->name('shop.category');

Route::get(ApiEndpoints::WEB_PRODUCTS, function () {
    return Inertia::render('Products/Index');
})->name('products.index');

Route::get(ApiEndpoints::WEB_PRODUCT_SHOW, function ($slug) {
    return Inertia::render('Products/Show', ['productSlug' => $slug]);
})->name('products.show');

// Checkout & Cart Pages (Inertia React Views)
Route::get(ApiEndpoints::WEB_CART, function () {
    return Inertia::render('Checkout/Cart');
})->name('cart');

Route::get(ApiEndpoints::WEB_CHECKOUT, function () {
    return Inertia::render('Checkout/Index');
})->name('checkout');

Route::get(ApiEndpoints::WEB_ORDER_SUCCESS, function (Request $request) {
    return Inertia::render('Checkout/Success', ['orderNumber' => $request->query('order')]);
})->name('order.success');

// PC Builder Interactive Pages
Route::get(ApiEndpoints::WEB_PC_BUILDER, function () {
    return Inertia::render('PcBuilder/Index');
})->name('pc-builder');

Route::get(ApiEndpoints::WEB_PC_BUILDER_CHOOSE, function ($categorySlug) {
    return Inertia::render('PcBuilder/SelectComponent', ['categorySlug' => $categorySlug]);
})->name('pc-builder.choose');

// Order Tracking Page
Route::get(ApiEndpoints::WEB_TRACK, function () {
    return Inertia::render('Track/Index');
})->name('track');

Route::get(ApiEndpoints::WEB_TRACK_ORDER, function () {
    return Inertia::render('Track/Index');
});

// Wishlist Page
Route::get(ApiEndpoints::WEB_WISHLIST, function () {
    return Inertia::render('Wishlist/Index');
})->name('wishlist');

// Compare Page
Route::get(ApiEndpoints::WEB_COMPARE, function () {
    return Inertia::render('Compare/Index');
})->name('compare');

// Stores & Showrooms Page
Route::get(ApiEndpoints::WEB_STORES, function () {
    return Inertia::render('Stores/Index');
})->name('stores');

// Customer Support Page
Route::get(ApiEndpoints::WEB_SUPPORT, function () {
    return Inertia::render('Support/Index');
})->name('support');

// Offers & Promos Page
Route::get(ApiEndpoints::WEB_OFFERS, function () {
    return Inertia::render('Offers/Index');
})->name('offers');

// Tech Journal & Blogs
Route::get(ApiEndpoints::WEB_BLOGS, function () {
    return Inertia::render('Blogs/Index');
})->name('blogs.index');

Route::get(ApiEndpoints::WEB_BLOG_SHOW, function ($slug) {
    return Inertia::render('Blogs/Show', ['slug' => $slug]);
})->name('blogs.show');

// Official Warranty & RMA Claim Portal
Route::get(ApiEndpoints::WEB_WARRANTY, function () {
    return Inertia::render('Warranty/Index');
})->name('warranty');

/*
 * Legacy root-level aliases for the stateful storefront endpoints.
 *
 * The browser client calls these under /api (see routes/api.php); these root paths
 * are kept so older links and any non-browser client keep working.
 */
Route::get(ApiEndpoints::COMPARE, [ComparisonController::class, 'index']);
Route::post(ApiEndpoints::COMPARE, [ComparisonController::class, 'store']);
Route::delete(ApiEndpoints::COMPARE_ITEM, [ComparisonController::class, 'destroy']);

Route::get(ApiEndpoints::CART, [CartController::class, 'index']);
Route::post(ApiEndpoints::CART, [CartController::class, 'store']);
Route::patch(ApiEndpoints::CART_ITEM, [CartController::class, 'update']);
Route::delete(ApiEndpoints::CART_ITEM, [CartController::class, 'destroy']);

Route::post(ApiEndpoints::CHECKOUT, [CheckoutController::class, 'process']);

// Authenticated Customer Dashboard Routes
Route::middleware(['auth'])->group(function () {
    Route::get(ApiEndpoints::DASHBOARD, [DashboardController::class, 'index'])->name('dashboard');
    Route::get(ApiEndpoints::ACCOUNT, [DashboardController::class, 'index'])->name('account');
    Route::post(ApiEndpoints::ACCOUNT_PROFILE, [DashboardController::class, 'updateProfile'])->name('account.profile');
    Route::post(ApiEndpoints::ACCOUNT_ADDRESS, [DashboardController::class, 'saveAddress'])->name('account.address');
    Route::delete(ApiEndpoints::ACCOUNT_ADDRESS_ITEM, [DashboardController::class, 'deleteAddress'])->name('account.address.delete');
    Route::put(ApiEndpoints::ACCOUNT_PASSWORD, [DashboardController::class, 'updatePassword'])->name('account.password');
    Route::post(ApiEndpoints::ACCOUNT_ORDER_CANCEL, [DashboardController::class, 'cancelOrder'])->name('account.orders.cancel');

    Route::get(ApiEndpoints::WEB_PROFILE, [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch(ApiEndpoints::WEB_PROFILE, [ProfileController::class, 'update'])->name('profile.update');
    Route::delete(ApiEndpoints::WEB_PROFILE, [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Authenticated Admin Dashboard Routes
Route::middleware(['auth', 'admin'])->prefix(ApiEndpoints::ADMIN_PREFIX)->name('admin.')->group(function () {
    Route::get(ApiEndpoints::ADMIN_DASHBOARD, [AdminDashboardController::class, 'dashboard'])->name('dashboard');
    Route::get(ApiEndpoints::ADMIN_ORDERS, [AdminDashboardController::class, 'orders'])->name('orders');
    Route::patch(ApiEndpoints::ADMIN_ORDERS_STATUS, [AdminDashboardController::class, 'updateOrderStatus'])->name('orders.status');
    Route::get(ApiEndpoints::ADMIN_PRODUCTS, [AdminDashboardController::class, 'products'])->name('products');
    Route::post(ApiEndpoints::ADMIN_PRODUCTS, [AdminDashboardController::class, 'storeProduct'])->name('products.store');
    Route::patch(ApiEndpoints::ADMIN_PRODUCTS_ITEM, [AdminDashboardController::class, 'updateProduct'])->name('products.update');
    Route::get(ApiEndpoints::ADMIN_CATEGORIES, [AdminDashboardController::class, 'categories'])->name('categories');
    Route::post(ApiEndpoints::ADMIN_CATEGORIES, [AdminDashboardController::class, 'storeCategory'])->name('categories.store');
    Route::patch(ApiEndpoints::ADMIN_CATEGORIES_ITEM, [AdminDashboardController::class, 'updateCategory'])->name('categories.update');
    Route::delete(ApiEndpoints::ADMIN_CATEGORIES_ITEM, [AdminDashboardController::class, 'destroyCategory'])->name('categories.destroy');
    Route::get(ApiEndpoints::ADMIN_BANNERS, [AdminDashboardController::class, 'banners'])->name('banners');
    Route::post(ApiEndpoints::ADMIN_BANNERS, [AdminDashboardController::class, 'storeBanner'])->name('banners.store');
    Route::patch(ApiEndpoints::ADMIN_BANNERS_ITEM, [AdminDashboardController::class, 'updateBanner'])->name('banners.update');
    Route::delete(ApiEndpoints::ADMIN_BANNERS_ITEM, [AdminDashboardController::class, 'destroyBanner'])->name('banners.destroy');
    Route::get(ApiEndpoints::ADMIN_COUPONS, [AdminDashboardController::class, 'coupons'])->name('coupons');
    Route::post(ApiEndpoints::ADMIN_COUPONS, [AdminDashboardController::class, 'storeCoupon'])->name('coupons.store');
    Route::match(['put', 'patch'], ApiEndpoints::ADMIN_COUPONS_ITEM, [AdminDashboardController::class, 'updateCoupon'])->name('coupons.update');
    Route::delete(ApiEndpoints::ADMIN_COUPONS_ITEM, [AdminDashboardController::class, 'destroyCoupon'])->name('coupons.destroy');
    Route::get(ApiEndpoints::ADMIN_STORES, [AdminDashboardController::class, 'storesView'])->name('stores');
    Route::post(ApiEndpoints::ADMIN_STORES, [AdminDashboardController::class, 'storeStore'])->name('stores.store');
    Route::match(['put', 'patch'], ApiEndpoints::ADMIN_STORES_ITEM, [AdminDashboardController::class, 'updateStore'])->name('stores.update');
    Route::delete(ApiEndpoints::ADMIN_STORES_ITEM, [AdminDashboardController::class, 'destroyStore'])->name('stores.destroy');
    Route::get(ApiEndpoints::ADMIN_SETTINGS, [AdminDashboardController::class, 'settingsView'])->name('settings');
    Route::post(ApiEndpoints::ADMIN_SETTINGS, [AdminDashboardController::class, 'updateSettings'])->name('settings.update');
    Route::get(ApiEndpoints::ADMIN_CUSTOMERS, [AdminDashboardController::class, 'customers'])->name('customers');
    Route::get(ApiEndpoints::ADMIN_BLOGS, [AdminDashboardController::class, 'blogsView'])->name('blogs');
    Route::post(ApiEndpoints::ADMIN_BLOGS, [AdminDashboardController::class, 'storeBlog'])->name('blogs.store');
    Route::put(ApiEndpoints::ADMIN_BLOGS_ITEM, [AdminDashboardController::class, 'updateBlog'])->name('blogs.update');
    Route::delete(ApiEndpoints::ADMIN_BLOGS_ITEM, [AdminDashboardController::class, 'destroyBlog'])->name('blogs.destroy');
    Route::get(ApiEndpoints::ADMIN_REVIEWS, [AdminDashboardController::class, 'reviewsView'])->name('reviews');
    Route::patch(ApiEndpoints::ADMIN_REVIEWS_STATUS, [AdminDashboardController::class, 'updateReviewStatus'])->name('reviews.status');
    Route::delete(ApiEndpoints::ADMIN_REVIEWS_ITEM, [AdminDashboardController::class, 'destroyReview'])->name('reviews.destroy');
    Route::get(ApiEndpoints::ADMIN_WARRANTY, [AdminDashboardController::class, 'warrantyView'])->name('warranty');
    Route::patch(ApiEndpoints::ADMIN_WARRANTY_STATUS, [AdminDashboardController::class, 'updateWarrantyStatus'])->name('warranty.status');
});

require __DIR__.'/auth.php';
