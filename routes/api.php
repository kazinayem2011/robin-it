<?php

use App\Constants\ApiEndpoints;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\PcBuilderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\WarrantyController;
use App\Http\Controllers\ComparisonController;
use App\Http\Controllers\WishlistController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Read-only catalogue endpoints
|--------------------------------------------------------------------------
| Public, cacheable, and throttled generously.
*/
Route::middleware('throttle:api')->group(function () {
    // Banners & Promos API
    Route::get(ApiEndpoints::BANNERS, [BannerController::class, 'index']);

    // Brands Featured API
    Route::get(ApiEndpoints::BRANDS_FEATURED, [BrandController::class, 'index']);

    // Categories API
    Route::get(ApiEndpoints::CATEGORIES_MEGA_MENU, [CategoryController::class, 'megaMenu']);
    Route::get(ApiEndpoints::CATEGORIES_FEATURED, [CategoryController::class, 'featured']);

    // Products & Homepage Data API
    Route::get(ApiEndpoints::PRODUCTS_FLASH_SALE, [ProductController::class, 'flashSale']);
    Route::get(ApiEndpoints::PRODUCTS_FEATURED, [ProductController::class, 'featured']);
    Route::get(ApiEndpoints::PRODUCTS_SUGGESTIONS, [ProductController::class, 'suggestions']);
    Route::get(ApiEndpoints::BUILDER_QUICK_SPECS, [ProductController::class, 'builderQuickSpecs']);
    Route::get(ApiEndpoints::PC_BUILDER_CATEGORIES, [ProductController::class, 'pcBuilderCategories']);
    Route::get(ApiEndpoints::PC_BUILDER_COMPONENTS, [ProductController::class, 'pcBuilderComponents']);
    Route::get(ApiEndpoints::PRODUCTS_INDEX, [ProductController::class, 'index']);
    Route::get(ApiEndpoints::PRODUCTS_SHOW, [ProductController::class, 'show']);

    // Product Reviews (read)
    Route::get(ApiEndpoints::PRODUCT_REVIEWS, [ReviewController::class, 'index']);

    // Showroom Stores API
    Route::get(ApiEndpoints::STORES, [StoreController::class, 'index']);

    // Blogs & Tech Journal API
    Route::get(ApiEndpoints::BLOGS, [BlogController::class, 'index']);
    Route::get(ApiEndpoints::BLOG_SHOW, [BlogController::class, 'show']);

    // PC Builder shared configuration
    Route::get(ApiEndpoints::PC_BUILDER_LOAD, [PcBuilderController::class, 'load']);

    // Compatibility check for the current build
    Route::post(ApiEndpoints::PC_BUILDER_CHECK, [PcBuilderController::class, 'check']);

    // Site Settings & Ticker API
    Route::get(ApiEndpoints::SETTINGS, [SettingController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Identity lookups
|--------------------------------------------------------------------------
| A wrong guess is cheap and a correct one exposes someone's order or claim,
| so these are throttled tightly.
*/
Route::middleware('throttle:lookup')->group(function () {
    Route::post(ApiEndpoints::ORDERS_TRACK, [CheckoutController::class, 'track']);
    Route::get(ApiEndpoints::WARRANTY_CHECK, [WarrantyController::class, 'check']);
});

/*
|--------------------------------------------------------------------------
| Anonymous submissions
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:submissions')->group(function () {
    Route::post(ApiEndpoints::WARRANTY_CLAIM, [WarrantyController::class, 'store']);
    Route::post(ApiEndpoints::PC_BUILDER_SAVE, [PcBuilderController::class, 'save']);
});

/*
|--------------------------------------------------------------------------
| Stateful storefront endpoints
|--------------------------------------------------------------------------
| Cart, comparison, coupons and checkout rely on the session (guest carts) and
| on CSRF protection, so they run through the `web` middleware group.
|
| The browser client calls these under the /api prefix (axios baseURL '/api').
| They were previously only registered at the site root, which meant every cart,
| compare and checkout request from the SPA 404'd.
*/
Route::middleware(['web', 'throttle:api'])->group(function () {
    Route::get(ApiEndpoints::CART, [CartController::class, 'index']);
    Route::post(ApiEndpoints::CART, [CartController::class, 'store']);
    Route::patch(ApiEndpoints::CART_ITEM, [CartController::class, 'update']);
    Route::delete(ApiEndpoints::CART_ITEM, [CartController::class, 'destroy']);

    Route::get(ApiEndpoints::COMPARE, [ComparisonController::class, 'index']);
    Route::post(ApiEndpoints::COMPARE, [ComparisonController::class, 'store']);
    Route::delete(ApiEndpoints::COMPARE_ITEM, [ComparisonController::class, 'destroy']);

    Route::post(ApiEndpoints::COUPONS_APPLY, [CouponController::class, 'apply']);
    Route::post(ApiEndpoints::CHECKOUT, [CheckoutController::class, 'process']);

    // Reviews are written by verified buyers, who are signed in via the session.
    Route::post(ApiEndpoints::PRODUCT_REVIEWS, [ReviewController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Authenticated customer endpoints
|--------------------------------------------------------------------------
| The `web` group has to run first: Sanctum's guard checks the session before it
| looks for a bearer token, and without StartSession there is no session to check.
| Without it a signed-in shopper's wishlist requests came back 401.
|
| Bearer-token clients still authenticate through the same auth:sanctum guard.
*/
Route::middleware(['web', 'auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Wishlist
    Route::get(ApiEndpoints::WISHLIST, [WishlistController::class, 'index']);
    Route::post(ApiEndpoints::WISHLIST, [WishlistController::class, 'store']);
    Route::delete(ApiEndpoints::WISHLIST_ITEM, [WishlistController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Admin API management endpoints
|--------------------------------------------------------------------------
*/
Route::middleware(['web', 'auth', 'admin', 'throttle:api'])->prefix(ApiEndpoints::ADMIN_PREFIX)->group(function () {
    Route::post(ApiEndpoints::ADMIN_CATEGORIES, [AdminDashboardController::class, 'storeCategory']);
    Route::patch(ApiEndpoints::ADMIN_CATEGORIES_ITEM, [AdminDashboardController::class, 'updateCategory']);
    Route::delete(ApiEndpoints::ADMIN_CATEGORIES_ITEM, [AdminDashboardController::class, 'destroyCategory']);
    Route::post(ApiEndpoints::ADMIN_PRODUCTS, [AdminDashboardController::class, 'storeProduct']);
    Route::patch(ApiEndpoints::ADMIN_PRODUCTS_ITEM, [AdminDashboardController::class, 'updateProduct']);
    Route::patch(ApiEndpoints::ADMIN_ORDERS_STATUS, [AdminDashboardController::class, 'updateOrderStatus']);
});
