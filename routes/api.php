<?php

use App\Constants\ApiEndpoints;
use App\Http\Controllers\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Admin\BlogController as AdminBlogController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\ExpenseController as AdminExpenseController;
use App\Http\Controllers\Admin\MediaUploadController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\ShowroomController as AdminShowroomController;
use App\Http\Controllers\Admin\StockController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\WarrantyController as AdminWarrantyController;
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
use App\Http\Controllers\Api\StockNotificationController;
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
    // Must be declared before the {slug} route below, or "filters" is matched
    // as a product slug and this endpoint 404s.
    Route::get(ApiEndpoints::PRODUCTS_FILTERS, [ProductController::class, 'filters']);
    Route::get(ApiEndpoints::STOCK_NOTIFY_COUNT, [StockNotificationController::class, 'count']);
    Route::get(ApiEndpoints::PRODUCT_BRANCHES, [ProductController::class, 'branchAvailability']);
    Route::get(ApiEndpoints::PRODUCTS_SHOW, [ProductController::class, 'show']);

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
    // Anonymous, and it sends mail, so it is rate-limited like the rest.
    Route::post(ApiEndpoints::STOCK_NOTIFY, [StockNotificationController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Stateful storefront endpoints
|--------------------------------------------------------------------------
| Cart, comparison, coupons and checkout rely on the session (guest carts) and
| on CSRF protection, so they run through the `web` middleware group.
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
    // Reading reviews needs the session too, not just writing one. Without
    // `web` there is no session, so Auth::user() was null for a signed-in
    // customer and the page told them to log in — while they were logged in.
    Route::get(ApiEndpoints::PRODUCT_REVIEWS, [ReviewController::class, 'index']);
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
    Route::get('/user', fn (Request $request) => $request->user());

    // Wishlist
    Route::get(ApiEndpoints::WISHLIST, [WishlistController::class, 'index']);
    Route::post(ApiEndpoints::WISHLIST, [WishlistController::class, 'store']);
    Route::delete(ApiEndpoints::WISHLIST_ITEM, [WishlistController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Admin management endpoints
|--------------------------------------------------------------------------
| The admin UI talks to these through axios, whose baseURL is '/api'. This is
| the only place they are declared: they used to be registered here *and* at
| the site root under /admin, pointing at the same controller methods, so every
| one of those methods carried a branch asking which shape to answer in.
|
| The `web` group is required for the session, and `admin` for the role check.
*/
Route::middleware(['web', 'auth', 'admin', 'throttle:api'])
    ->prefix(ApiEndpoints::ADMIN_PREFIX)
    ->name('api.admin.')
    ->group(function () {
        // Catalogue
        Route::post(ApiEndpoints::ADMIN_CATEGORIES, [AdminCategoryController::class, 'store']);
        Route::patch(ApiEndpoints::ADMIN_CATEGORIES_ITEM, [AdminCategoryController::class, 'update']);
        Route::delete(ApiEndpoints::ADMIN_CATEGORIES_ITEM, [AdminCategoryController::class, 'destroy']);
        Route::post(ApiEndpoints::ADMIN_PRODUCTS, [AdminProductController::class, 'store']);
        Route::patch(ApiEndpoints::ADMIN_PRODUCTS_ITEM, [AdminProductController::class, 'update']);

        // Orders
        Route::patch(ApiEndpoints::ADMIN_ORDERS_STATUS, [AdminOrderController::class, 'updateStatus']);
        Route::post(ApiEndpoints::ADMIN_ORDERS_RETURN, [StockController::class, 'returnOrder']);

        // Stock
        Route::post(ApiEndpoints::ADMIN_STOCK_RECEIPTS, [StockController::class, 'receive']);
        Route::get(ApiEndpoints::ADMIN_STOCK_RECEIPTS, [StockController::class, 'receipts']);
        Route::post(ApiEndpoints::ADMIN_STOCK_ADJUST, [StockController::class, 'adjust']);
        Route::get(ApiEndpoints::ADMIN_STOCK_MOVEMENTS, [StockController::class, 'movements']);
        Route::get(ApiEndpoints::ADMIN_STOCK_UNITS, [StockController::class, 'units']);
        Route::post(ApiEndpoints::ADMIN_STOCK_TRANSFER, [StockController::class, 'transfer']);
        Route::get(ApiEndpoints::ADMIN_STOCK_BRANCHES, [StockController::class, 'branches']);

        // Suppliers. `options` is declared before the {id} route or it is
        // matched as an id.
        Route::get(ApiEndpoints::ADMIN_SUPPLIER_OPTIONS, [SupplierController::class, 'options']);
        Route::get(ApiEndpoints::ADMIN_SUPPLIERS, [SupplierController::class, 'index']);
        Route::post(ApiEndpoints::ADMIN_SUPPLIERS, [SupplierController::class, 'store']);
        Route::match(['put', 'patch'], ApiEndpoints::ADMIN_SUPPLIERS_ITEM, [SupplierController::class, 'update']);
        Route::delete(ApiEndpoints::ADMIN_SUPPLIERS_ITEM, [SupplierController::class, 'destroy']);

        // Banners
        Route::post(ApiEndpoints::ADMIN_BANNERS, [AdminBannerController::class, 'store']);
        Route::patch(ApiEndpoints::ADMIN_BANNERS_ITEM, [AdminBannerController::class, 'update']);
        Route::delete(ApiEndpoints::ADMIN_BANNERS_ITEM, [AdminBannerController::class, 'destroy']);

        // Coupons
        Route::post(ApiEndpoints::ADMIN_COUPONS, [AdminCouponController::class, 'store']);
        Route::match(['put', 'patch'], ApiEndpoints::ADMIN_COUPONS_ITEM, [AdminCouponController::class, 'update']);
        Route::delete(ApiEndpoints::ADMIN_COUPONS_ITEM, [AdminCouponController::class, 'destroy']);

        // Showrooms
        Route::post(ApiEndpoints::ADMIN_STORES, [AdminShowroomController::class, 'store']);
        Route::match(['put', 'patch'], ApiEndpoints::ADMIN_STORES_ITEM, [AdminShowroomController::class, 'update']);
        Route::delete(ApiEndpoints::ADMIN_STORES_ITEM, [AdminShowroomController::class, 'destroy']);

        // Tech journal
        Route::post(ApiEndpoints::ADMIN_BLOGS, [AdminBlogController::class, 'store']);
        Route::put(ApiEndpoints::ADMIN_BLOGS_ITEM, [AdminBlogController::class, 'update']);
        Route::delete(ApiEndpoints::ADMIN_BLOGS_ITEM, [AdminBlogController::class, 'destroy']);

        // Reviews
        Route::patch(ApiEndpoints::ADMIN_REVIEWS_STATUS, [AdminReviewController::class, 'updateStatus']);
        Route::delete(ApiEndpoints::ADMIN_REVIEWS_ITEM, [AdminReviewController::class, 'destroy']);

        // Warranty
        Route::patch(ApiEndpoints::ADMIN_WARRANTY_STATUS, [AdminWarrantyController::class, 'updateStatus']);

        // Running costs
        Route::post(ApiEndpoints::ADMIN_EXPENSES, [AdminExpenseController::class, 'store']);
        Route::match(['put', 'patch'], ApiEndpoints::ADMIN_EXPENSES_ITEM, [AdminExpenseController::class, 'update']);
        Route::delete(ApiEndpoints::ADMIN_EXPENSES_ITEM, [AdminExpenseController::class, 'destroy']);

        // Settings
        Route::post(ApiEndpoints::ADMIN_SETTINGS, [AdminSettingController::class, 'update']);
        Route::post(ApiEndpoints::ADMIN_SETTINGS_TEST_EMAIL, [AdminSettingController::class, 'sendTestEmail']);

        // Media uploads
        Route::post(ApiEndpoints::ADMIN_MEDIA, [MediaUploadController::class, 'store']);
        Route::delete(ApiEndpoints::ADMIN_MEDIA, [MediaUploadController::class, 'destroy']);
    });
