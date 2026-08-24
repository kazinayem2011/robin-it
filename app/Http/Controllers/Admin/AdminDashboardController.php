<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Mail\OrderStatusUpdatedMail;
use App\Mail\TestConfigurationMail;
use App\Models\Banner;
use App\Models\BlogPost;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\SiteSetting;
use App\Models\Store;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\OrderService;
use App\Services\PcCompatibilityService;
use App\Services\ProductVariantService;
use App\Services\StockService;
use App\Support\MailSettings;
use App\Support\QueueHealth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    /**
     * Executive Overview & KPI Dashboard.
     */
    public function dashboard(): Response
    {
        $totalRevenue = (float) Order::where('status', '!=', 'cancelled')->sum('total');
        $totalOrders = Order::count();
        $pendingOrders = Order::whereIn('status', ['pending', 'processing', 'shipped'])->count();
        $totalCustomers = User::where('role', 'customer')->count();
        $lowStockProducts = Product::where('stock_quantity', '<=', 10)->with(['brand', 'images'])->get();

        $recentOrders = Order::with(['user', 'items.product'])
            ->latest()
            ->take(8)
            ->get();

        $topSelling = Product::with(['brand', 'images'])
            ->where('is_featured', true)
            ->take(5)
            ->get();

        $metrics = [
            'total_revenue' => $totalRevenue,
            'total_orders' => $totalOrders,
            'pending_orders' => $pendingOrders,
            'total_customers' => $totalCustomers,
            'total_products' => Product::count(),
            'low_stock_count' => $lowStockProducts->count(),
        ];

        return Inertia::render('Admin/Dashboard', [
            'metrics' => $metrics,
            'recentOrders' => $recentOrders,
            'lowStockProducts' => $lowStockProducts,
            'topSelling' => $topSelling,
            // A dead queue worker means customers silently stop receiving
            // order emails. Nothing else in the app would say so.
            'queueHealth' => QueueHealth::check(),
        ]);
    }

    /**
     * Orders Management View.
     */
    public function orders(Request $request): Response
    {
        $status = $request->input('status', 'all');
        $search = $request->input('search', '');

        $query = Order::with(['user', 'items.product.images'])->latest();

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'LIKE', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('phone', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%");
                    });
            });
        }

        $orders = $query->paginate(15)->withQueryString();

        return Inertia::render('Admin/Orders', [
            'orders' => $orders,
            'currentStatus' => $status,
            'search' => $search,
        ]);
    }

    /**
     * Update an order status.
     */
    public function updateOrderStatus(Request $request, OrderService $orderService, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:'.implode(',', Order::STATUSES),
            'payment_status' => 'nullable|in:'.implode(',', Order::PAYMENT_STATUSES),
        ]);

        $order = Order::findOrFail($id);

        // Goes through the service so cancelling an order returns its stock to the shelf.
        $orderService->updateOrderStatus($order, $validated['status']);

        if (array_key_exists('payment_status', $validated) && $validated['payment_status'] !== null) {
            $order->payment_status = $validated['payment_status'];
            $order->save();
        }

        // Send status update notification email
        try {
            $customerEmail = $order->user?->email ?? ($order->shipping_address['email'] ?? null);
            if ($customerEmail) {
                Mail::to($customerEmail)->send(new OrderStatusUpdatedMail($order));
            }
        } catch (\Throwable $e) {
            Log::warning("Could not dispatch OrderStatusUpdatedMail: {$e->getMessage()}");
        }

        if ($request->wantsJson() || $request->is('api/*') || $request->ajax()) {
            return $this->successResponse($order, "Order #{$order->order_number} status updated to ".ucfirst($order->status).'.');
        }

        return back()->with('success', "Order #{$order->order_number} status updated to ".ucfirst($order->status).'.');
    }

    /**
     * Products & Inventory Management View.
     */
    public function products(Request $request): Response
    {
        $search = $request->input('search', '');
        $categoryId = $request->input('category_id', '');

        // specifications and the category chain are eager-loaded because the
        // compatibility gap check below reads both. Loading them per product
        // instead turned this page into 61 queries for 20 rows.
        $query = Product::with([
            'category.parent.parent', 'brand', 'images', 'variants', 'specifications',
        ])
            // withExists rather than asking per product: checking each row
            // individually took this page from 23 queries to 52.
            ->withExists(['stockMovements', 'orderItems'])
            ->latest();

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('slug', 'LIKE', "%{$search}%");
            });
        }

        if (! empty($categoryId)) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->paginate(20)->withQueryString();

        // Which builder components cannot be compatibility-checked because a
        // spec the engine reads is missing. It treats an absent spec as
        // "unknown" rather than a failure, which is right at check time and
        // invisible at catalogue time — the shop cannot tell "these fit" from
        // "we never said".
        $compatibility = app(PcCompatibilityService::class);
        $products->getCollection()->each(function (Product $product) use ($compatibility) {
            $product->setAttribute('missing_specs', $compatibility->missingSpecsFor($product));

            // Whether single/variant can still be changed. Once a product has
            // stock or appears on an order its shape is fixed, so the form
            // should say so rather than letting someone try and be refused.
            $product->setAttribute(
                'structure_locked',
                (bool) $product->stock_movements_exists || (bool) $product->order_items_exists
            );
        });

        $categories = Category::all(['id', 'name', 'slug', 'parent_id']);
        $brands = Brand::all(['id', 'name', 'slug']);

        return Inertia::render('Admin/Products', [
            'products' => $products,
            'categories' => $categories,
            'brands' => $brands,
            'filters' => [
                'search' => $search,
                'category_id' => $categoryId,
            ],
        ]);
    }

    /**
     * Quick Update a Product (Price, Discount Price, Stock, Active, Featured).
     */
    public function updateProduct(Request $request, ProductVariantService $variants, $productId)
    {
        $product = Product::findOrFail($productId);

        $validated = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'name' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0',
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'image_path' => 'nullable|string',
            // Not stock: the level at which to buy more. Safe to edit here
            // because it moves nothing.
            'reorder_level' => 'nullable|integer|min:0|max:100000',

            // Options. Stock is deliberately absent from every one of these:
            // editing a product must never move a unit.
            'has_variants' => 'nullable|boolean',
            'variant_attributes' => 'nullable|array',
            'variant_attributes.*' => 'string|max:60',
            'variants' => 'nullable|array',
            'variants.*.id' => 'nullable|integer',
            'variants.*.options' => 'nullable|array',
            'variants.*.name' => 'nullable|string|max:180',
            'variants.*.sku' => 'nullable|string|max:80',
            'variants.*.price' => 'nullable|numeric|min:0',
            'variants.*.discount_price' => 'nullable|numeric|min:0',
            'variants.*.image_url' => 'nullable|string|max:2048',
            'variants.*.reorder_level' => 'nullable|integer|min:0|max:100000',
            'variants.*.is_active' => 'nullable|boolean',
            // Only read when switching a single product over to options, where it
            // says how the existing shelf is split. It never adds stock.
            'variants.*.opening_stock' => 'nullable|integer|min:0',
        ]);

        // stock_quantity is not accepted here at all. An admin who could type an
        // absolute quantity could save a form opened before a sale and put the
        // sold units back on the shelf; stock moves only through the ledger.
        $scalar = collect($validated)->except(['has_variants', 'variant_attributes', 'variants'])->all();

        // A posted `null` must not blank out a NOT NULL column such as name or price.
        $product->update(array_filter(
            $scalar,
            fn ($value, $key) => $value !== null || in_array($key, ['brand_id', 'discount_price'], true),
            ARRAY_FILTER_USE_BOTH
        ));

        $this->applyVariantChanges($request, $variants, $product, $validated);

        if (! empty($validated['image_path'])) {
            $primaryImage = $product->images()->where('is_primary', true)->first();
            if ($primaryImage) {
                $primaryImage->update(['image_path' => $validated['image_path']]);
            } else {
                $product->images()->create([
                    'image_path' => $validated['image_path'],
                    'is_primary' => true,
                ]);
            }
        }

        if ($request->wantsJson() || $request->is('api/*') || $request->ajax()) {
            return $this->successResponse($product, "Product '{$product->name}' updated successfully.");
        }

        return back()->with('success', "Product '{$product->name}' updated successfully.");
    }

    /**
     * Apply an option change requested by the product form.
     *
     * Three distinct moves, and only the conversions touch stock — and then only
     * to carry the same units across, never to change the total:
     *   single  -> options   the existing shelf is split across the new options
     *   options -> single    every option's stock is drained back to the product
     *   options -> options   labels and prices change, stock stays where it is
     */
    private function applyVariantChanges(
        Request $request,
        ProductVariantService $variants,
        Product $product,
        array $validated
    ): void {
        if (! $request->has('has_variants') && ! $request->has('variants')) {
            return;
        }

        $wantsVariants = (bool) ($validated['has_variants'] ?? $product->has_variants);
        $definitions = $validated['variants'] ?? [];
        $attributes = $validated['variant_attributes'] ?? ($product->variant_attributes ?? []);
        $userId = $request->user()?->id;

        if ($wantsVariants && ! $product->has_variants) {
            $variants->convertToVariants($product, $attributes, $definitions, $userId);

            return;
        }

        if (! $wantsVariants && $product->has_variants) {
            $variants->convertToSingle($product, $userId);

            return;
        }

        if ($wantsVariants && $definitions !== []) {
            $variants->syncVariants($product, $attributes, $definitions);
        }
    }

    /**
     * Create a New Product.
     */
    public function storeProduct(Request $request, StockService $stock)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'is_featured' => 'nullable|boolean',
            'image_path' => 'nullable|string',
            'reorder_level' => 'nullable|integer|min:0|max:100000',
        ]);

        $validated['slug'] = $this->uniqueSlug(Product::class, $validated['name']);
        $validated['is_active'] = true;

        $opening = (int) ($validated['stock_quantity'] ?? 0);

        $product = Product::create($validated);

        // The one moment an absolute quantity is legitimate: stock already on the
        // shelf when the product is first entered. It is written to the ledger as
        // an opening balance, and from here on only purchases, sales, returns and
        // audited adjustments can move it.
        if ($opening > 0) {
            $stock->recordOpeningBalance($product, null, $opening, $request->user()?->id);
        }

        if (! empty($validated['image_path'])) {
            $product->images()->create([
                'image_path' => $validated['image_path'],
                'is_primary' => true,
            ]);
        }

        if ($request->wantsJson() || $request->is('api/*') || $request->ajax()) {
            return $this->successResponse($product, "New product '{$product->name}' created successfully.", 201);
        }

        return back()->with('success', "New product '{$product->name}' created successfully.");
    }

    /**
     * Categories Management View.
     */
    public function categories(): Response
    {
        $categories = Category::whereNull('parent_id')
            ->with(['children.children', 'products'])
            ->orderBy('id', 'asc')
            ->get();

        // Flat list for parent selector (Level 1 & Level 2 categories)
        $parentOptions = Category::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('parent_id')
                    ->orWhereIn('parent_id', Category::whereNull('parent_id')->pluck('id'));
            })
            ->with('parent')
            ->orderBy('name', 'asc')
            ->get()
            ->map(function ($c) {
                return [
                    'id' => $c->id,
                    'name' => $c->parent ? "{$c->parent->name} > {$c->name}" : $c->name,
                    'level' => $c->parent_id ? ($c->parent->parent_id ? 3 : 2) : 1,
                ];
            });

        return Inertia::render('Admin/Categories', [
            'categories' => $categories,
            'parentOptions' => $parentOptions,
        ]);
    }

    /**
     * Store a newly created category (Root, L2 Subcategory, or L3 Series).
     */
    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:120|unique:categories,slug',
            'parent_id' => 'nullable|exists:categories,id',
            'icon' => 'nullable|string|max:50',
            'badge' => 'nullable|string|max:20',
            'is_offer' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $slug = ! empty($validated['slug'])
            ? Str::slug($validated['slug'])
            : Str::slug($validated['name']);

        // Ensure unique slug
        $originalSlug = $slug;
        $count = 1;
        while (Category::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$count}";
            $count++;
        }

        $category = Category::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'parent_id' => $validated['parent_id'] ?? null,
            'icon' => $validated['icon'] ?? ($validated['parent_id'] ? null : 'Layers'),
            'badge' => $validated['badge'] ?? null,
            'is_offer' => $validated['is_offer'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if ($request->wantsJson() || $request->is('api/*') || $request->ajax()) {
            return $this->successResponse($category, "Category '{$category->name}' created successfully.", 201);
        }

        return back()->with('success', "Category '{$category->name}' created successfully.");
    }

    /**
     * Update an existing category.
     */
    public function updateCategory(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:120|unique:categories,slug,'.$category->id,
            'parent_id' => 'nullable|exists:categories,id',
            'icon' => 'nullable|string|max:50',
            'badge' => 'nullable|string|max:20',
            'is_offer' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $slug = ! empty($validated['slug'])
            ? Str::slug($validated['slug'])
            : Str::slug($validated['name']);

        $category->update([
            'name' => $validated['name'],
            'slug' => $slug,
            'parent_id' => $validated['parent_id'] ?? null,
            'icon' => $validated['icon'] ?? $category->icon,
            'badge' => $validated['badge'] ?? null,
            'is_offer' => $validated['is_offer'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if ($request->wantsJson() || $request->is('api/*') || $request->ajax()) {
            return $this->successResponse($category, "Category '{$category->name}' updated successfully.");
        }

        return back()->with('success', "Category '{$category->name}' updated successfully.");
    }

    /**
     * Delete a category, refusing when doing so would destroy catalogue data.
     *
     * products.category_id cascades on delete, so the previous implementation
     * silently wiped every product in the subtree, and orphaned grandchildren
     * were promoted to root categories. Now the admin is told what is in the way.
     */
    public function destroyCategory(Request $request, $id)
    {
        $category = Category::findOrFail($id);
        $name = $category->name;

        $descendantIds = Category::getDescendantIds($category);
        $productCount = Product::whereIn('category_id', $descendantIds)->count();

        if ($productCount > 0) {
            $message = "'{$name}' still holds {$productCount} product(s), including its subcategories. "
                .'Move or delete those products first — deleting the category would remove them permanently.';

            if ($this->expectsJson($request)) {
                return $this->errorResponse($message, 422, ApiCode::VALIDATION_ERROR, [
                    'product_count' => $productCount,
                    'category_ids' => array_values($descendantIds),
                ]);
            }

            return back()->with('error', $message);
        }

        DB::transaction(function () use ($category, $descendantIds) {
            // Delete deepest-first so no category is ever left pointing at a missing parent.
            $ids = array_values(array_diff($descendantIds, [$category->id]));

            Category::whereIn('id', $ids)
                ->orderByDesc('id')
                ->get()
                ->each
                ->delete();

            $category->delete();
        });

        $message = "Category '{$name}' deleted successfully.";

        if ($this->expectsJson($request)) {
            return $this->successResponse([], $message);
        }

        return back()->with('success', $message);
    }

    /**
     * Whether this request wants a JSON envelope rather than an Inertia redirect.
     */
    private function expectsJson(Request $request): bool
    {
        return $request->wantsJson() || $request->is('api/*') || $request->ajax();
    }

    /**
     * A readable, collision-free slug. The previous `-rand(100,999)` suffix could
     * still collide and produced noisy URLs like "rtx-4090-517".
     *
     * @param  class-string<Model>  $modelClass
     */
    private function uniqueSlug(string $modelClass, string $source): string
    {
        $base = Str::slug($source) ?: 'item';
        $slug = $base;
        $suffix = 2;

        while ($modelClass::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * Customers Directory View.
     */
    public function customers(Request $request): Response
    {
        $search = $request->input('search', '');

        $query = User::where('role', 'customer')
            ->withCount('orders')
            ->withSum('orders', 'total')
            ->latest();

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        $customers = $query->paginate(20)->withQueryString();

        return Inertia::render('Admin/Customers', [
            'customers' => $customers,
            'search' => $search,
        ]);
    }

    /**
     * Banners & Hero Sliders Manager.
     */
    public function banners(): Response
    {
        $banners = Banner::orderBy('position')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Admin/Banners', [
            'banners' => $banners,
        ]);
    }

    /**
     * Store new Banner.
     */
    public function storeBanner(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:150',
            'subtitle' => 'nullable|string|max:255',
            'badge' => 'nullable|string|max:50',
            'image_path' => 'required|string',
            'link_url' => 'nullable|string|max:255',
            'button_text' => 'nullable|string|max:50',
            'position' => 'required|in:hero,promo_top,promo_side,popup',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ]);

        $banner = Banner::create($validated);

        if ($request->wantsJson() || $request->ajax()) {
            return $this->successResponse($banner, 'Banner created successfully.', 201);
        }

        return back()->with('success', 'Banner created successfully.');
    }

    /**
     * Update Banner.
     */
    public function updateBanner(Request $request, $id)
    {
        $banner = Banner::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:150',
            'subtitle' => 'nullable|string|max:255',
            'badge' => 'nullable|string|max:50',
            'image_path' => 'required|string',
            'link_url' => 'nullable|string|max:255',
            'button_text' => 'nullable|string|max:50',
            'position' => 'required|in:hero,promo_top,promo_side,popup',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ]);

        $banner->update($validated);

        if ($request->wantsJson() || $request->ajax()) {
            return $this->successResponse($banner, 'Banner updated successfully.');
        }

        return back()->with('success', 'Banner updated successfully.');
    }

    /**
     * Delete Banner.
     */
    public function destroyBanner($id)
    {
        $banner = Banner::findOrFail($id);
        $banner->delete();

        if (request()->wantsJson() || request()->ajax()) {
            return $this->successResponse([], 'Banner deleted.');
        }

        return back()->with('success', 'Banner deleted successfully.');
    }

    /**
     * Coupons & Discounts Manager.
     */
    public function coupons(): Response
    {
        $coupons = Coupon::with(['products:id,name', 'categories:id,name'])->latest()->get();

        return Inertia::render('Admin/Coupons', [
            'coupons' => $coupons,
            // The scope pickers need something to choose from.
            'products' => Product::where('is_active', true)
                ->orderBy('name')->get(['id', 'name']),
            'categories' => Category::where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'parent_id']),
            'scopes' => Coupon::SCOPES,
        ]);
    }

    /**
     * Store Coupon.
     */
    public function storeCoupon(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:coupons,code',
            'description' => 'nullable|string|max:255',
            'discount_type' => 'required|in:percent,fixed',
            'discount_value' => 'required|numeric|min:0',
            'min_spend' => 'nullable|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'per_user_limit' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date',
            'is_active' => 'boolean',

            // Restrict the promo to part of the catalogue. Category scope covers
            // everything beneath the categories named.
            'scope' => 'nullable|in:'.implode(',', Coupon::SCOPES),
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
        ]);

        $coupon = Coupon::create(collect($validated)->except(['product_ids', 'category_ids'])->all());

        $this->syncCouponScope($coupon, $validated);

        if ($request->wantsJson() || $request->ajax()) {
            return $this->successResponse($coupon->load('products:id,name', 'categories:id,name'), 'Coupon created.', 201);
        }

        return back()->with('success', 'Coupon created successfully.');
    }

    /**
     * Update Coupon.
     */
    public function updateCoupon(Request $request, $id)
    {
        $coupon = Coupon::findOrFail($id);

        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:coupons,code,'.$coupon->id,
            'description' => 'nullable|string|max:255',
            'discount_type' => 'required|in:percent,fixed',
            'discount_value' => 'required|numeric|min:0',
            'min_spend' => 'nullable|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'per_user_limit' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date',
            'is_active' => 'boolean',

            // Restrict the promo to part of the catalogue. Category scope covers
            // everything beneath the categories named.
            'scope' => 'nullable|in:'.implode(',', Coupon::SCOPES),
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
        ]);

        $coupon->update(collect($validated)->except(['product_ids', 'category_ids'])->all());

        $this->syncCouponScope($coupon, $validated);

        if ($request->wantsJson() || $request->ajax()) {
            return $this->successResponse($coupon->load('products:id,name', 'categories:id,name'), 'Coupon updated successfully.');
        }

        return back()->with('success', 'Coupon updated successfully.');
    }

    /**
     * Attach the products or categories a scoped coupon covers.
     *
     * The lists are cleared for the scopes that do not apply, so a coupon
     * switched back to "whole order" cannot keep a stale restriction that would
     * quietly change what it discounts.
     */
    private function syncCouponScope(Coupon $coupon, array $validated): void
    {
        $scope = $validated['scope'] ?? $coupon->scope ?? Coupon::SCOPE_ALL;

        $coupon->products()->sync(
            $scope === Coupon::SCOPE_PRODUCTS ? ($validated['product_ids'] ?? []) : []
        );

        $coupon->categories()->sync(
            $scope === Coupon::SCOPE_CATEGORIES ? ($validated['category_ids'] ?? []) : []
        );
    }

    /**
     * Delete Coupon.
     */
    public function destroyCoupon($id)
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->delete();

        if (request()->wantsJson() || request()->ajax()) {
            return $this->successResponse([], 'Coupon deleted.');
        }

        return back()->with('success', 'Coupon deleted.');
    }

    /**
     * Showrooms & Branch Outlets Manager.
     */
    public function storesView(): Response
    {
        $stores = Store::orderBy('city')->get();

        return Inertia::render('Admin/Stores', [
            'stores' => $stores,
        ]);
    }

    /**
     * Store Branch.
     */
    public function storeStore(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'branch_type' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'address' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'email' => 'nullable|email|max:100',
            'opening_hours' => 'required|string|max:150',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        // sort_order defaults to 0 in the schema and the form does not collect
        // it, so a new branch used to land above the flagship showroom. Append
        // to the end unless a position was given.
        $validated['sort_order'] = $validated['sort_order']
            ?? ((int) Store::max('sort_order') + 1);

        $store = Store::create($validated);

        if ($request->wantsJson() || $request->ajax()) {
            return $this->successResponse($store, 'Branch added.', 201);
        }

        return back()->with('success', 'Branch created successfully.');
    }

    /**
     * Update Branch.
     */
    public function updateStore(Request $request, $id)
    {
        $store = Store::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'branch_type' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'address' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'email' => 'nullable|email|max:100',
            'opening_hours' => 'required|string|max:150',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        // Omitting the field means "leave the position as it is".
        if (! isset($validated['sort_order'])) {
            unset($validated['sort_order']);
        }

        $store->update($validated);

        if ($request->wantsJson() || $request->ajax()) {
            return $this->successResponse($store, 'Branch updated successfully.');
        }

        return back()->with('success', 'Branch updated successfully.');
    }

    /**
     * Delete Branch.
     */
    public function destroyStore($id)
    {
        $store = Store::findOrFail($id);
        $store->delete();

        if (request()->wantsJson() || request()->ajax()) {
            return $this->successResponse([], 'Branch deleted.');
        }

        return back()->with('success', 'Branch deleted.');
    }

    /**
     * Site Settings & Announcement Ticker.
     */
    public function settingsView(): Response
    {
        // Send everything except the SMTP password, which must not travel back
        // to the browser. The form shows whether one is set instead.
        $settings = SiteSetting::all()
            ->reject(fn ($setting) => $setting->key === 'mail_password')
            ->values();

        return Inertia::render('Admin/Settings', [
            'settings' => $settings,
            'mailPasswordSet' => MailSettings::isPasswordSet(),
        ]);
    }

    /**
     * Update Site Settings.
     */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'settings' => 'required|array',
            'settings.*' => [
                'nullable',
                function (string $attribute, $value, $fail) {
                    if (! is_scalar($value)) {
                        $fail('Each setting must be a single text, number or on/off value.');
                    }
                },
            ],
        ]);

        foreach ($data['settings'] as $key => $val) {
            $key = (string) $key;
            $value = is_bool($val) ? ($val ? '1' : '0') : (string) $val;

            // The SMTP password is a live credential; encrypt it at rest. An
            // empty submission means "leave it as it is" rather than "clear it",
            // because the form never receives the current value to send back.
            if ($key === 'mail_password') {
                if ($value === '') {
                    continue;
                }
                $value = MailSettings::encryptPassword($value);
            }

            SiteSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        SiteSetting::flushCache(array_keys($data['settings']));

        if ($request->wantsJson() || $request->ajax()) {
            return $this->successResponse([], 'Settings saved successfully.');
        }

        return back()->with('success', 'Settings updated successfully.');
    }

    /**
     * Send a test message using the SMTP settings currently saved.
     *
     * Sends synchronously and on-demand rather than through the queue, so the
     * admin gets the actual SMTP error back instead of a silent failed job.
     */
    public function sendTestEmail(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ], [
            'email.required' => 'Enter an address to send the test to.',
        ]);

        MailSettings::apply();

        try {
            Mail::mailer(config('mail.default'))
                ->to($validated['email'])
                ->sendNow(new TestConfigurationMail);
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Could not send: '.$e->getMessage(),
                422,
                ApiCode::GENERIC
            );
        }

        return $this->successResponse([
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
        ], "Test email sent to {$validated['email']}. Check the inbox to confirm.");
    }

    /**
     * Tech Journal & Blog Articles Manager.
     */
    public function blogsView(): Response
    {
        $blogs = BlogPost::latest()->get();

        return Inertia::render('Admin/Blogs', [
            'blogs' => $blogs,
        ]);
    }

    /**
     * Store new Blog Post.
     */
    public function storeBlog(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'category' => 'required|string|max:50',
            'excerpt' => 'required|string|max:300',
            'content' => 'required|string',
            'image_path' => 'required|string',
            'author_name' => 'required|string|max:100',
            'author_role' => 'nullable|string|max:100',
            'read_time' => 'required|string|max:20',
            'is_published' => 'boolean',
        ]);

        $validated['slug'] = $this->uniqueSlug(BlogPost::class, $validated['title']);
        // `boolean` rules leave the key absent when the field isn't posted at all.
        $validated['is_published'] = (bool) ($validated['is_published'] ?? false);
        $validated['published_at'] = $validated['is_published'] ? now() : null;

        $blog = BlogPost::create($validated);

        if ($request->wantsJson() || $request->ajax()) {
            return $this->successResponse($blog, 'Blog post published successfully.', 201);
        }

        return back()->with('success', 'Blog post published successfully.');
    }

    /**
     * Update Blog Post.
     */
    public function updateBlog(Request $request, $id)
    {
        $blog = BlogPost::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'category' => 'required|string|max:50',
            'excerpt' => 'required|string|max:300',
            'content' => 'required|string',
            'image_path' => 'required|string',
            'author_name' => 'required|string|max:100',
            'author_role' => 'nullable|string|max:100',
            'read_time' => 'required|string|max:20',
            'is_published' => 'boolean',
        ]);

        $validated['is_published'] = (bool) ($validated['is_published'] ?? false);

        if ($validated['is_published'] && ! $blog->published_at) {
            $validated['published_at'] = now();
        }

        $blog->update($validated);

        if ($request->wantsJson() || $request->ajax()) {
            return $this->successResponse($blog, 'Blog post updated successfully.');
        }

        return back()->with('success', 'Blog post updated successfully.');
    }

    /**
     * Delete Blog Post.
     */
    public function destroyBlog($id)
    {
        $blog = BlogPost::findOrFail($id);
        $blog->delete();

        if (request()->wantsJson() || request()->ajax()) {
            return $this->successResponse([], 'Blog post removed.');
        }

        return back()->with('success', 'Blog post removed.');
    }

    /**
     * Customer Reviews Moderation.
     *
     * Reviews are published on submission (they can only come from verified
     * buyers), but nothing let an admin take one down. This is that screen.
     */
    public function reviewsView(Request $request): Response
    {
        $status = $request->input('status', 'all');
        $search = trim((string) $request->input('search', ''));

        $query = ProductReview::with(['product:id,name,slug', 'user:id,name,email'])->latest();

        if ($status === 'published') {
            $query->where('is_approved', true);
        } elseif ($status === 'hidden') {
            $query->where('is_approved', false);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('comment', 'LIKE', "%{$search}%")
                    ->orWhere('title', 'LIKE', "%{$search}%")
                    ->orWhere('author_name', 'LIKE', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'LIKE', "%{$search}%"));
            });
        }

        return Inertia::render('Admin/Reviews', [
            'reviews' => $query->paginate(20)->withQueryString(),
            'filters' => ['status' => $status, 'search' => $search],
            'counts' => [
                'all' => ProductReview::count(),
                'published' => ProductReview::where('is_approved', true)->count(),
                'hidden' => ProductReview::where('is_approved', false)->count(),
            ],
        ]);
    }

    /**
     * Publish or hide a single review.
     */
    public function updateReviewStatus(Request $request, $id)
    {
        $review = ProductReview::findOrFail($id);

        $validated = $request->validate([
            'is_approved' => 'required|boolean',
        ]);

        $review->update(['is_approved' => $validated['is_approved']]);

        $message = $validated['is_approved']
            ? 'Review published.'
            : 'Review hidden from the storefront.';

        if ($this->expectsJson($request)) {
            return $this->successResponse($review, $message);
        }

        return back()->with('success', $message);
    }

    /**
     * Permanently remove a review.
     */
    public function destroyReview(Request $request, $id)
    {
        ProductReview::findOrFail($id)->delete();

        if ($this->expectsJson($request)) {
            return $this->successResponse([], 'Review deleted.');
        }

        return back()->with('success', 'Review deleted.');
    }

    /**
     * RMA & Warranty Claims Manager.
     */
    public function warrantyView(): Response
    {
        $claims = WarrantyClaim::latest()->get();

        return Inertia::render('Admin/Warranty', [
            'claims' => $claims,
        ]);
    }

    /**
     * Update RMA Status & Diagnostic Notes.
     */
    public function updateWarrantyStatus(Request $request, $id)
    {
        $claim = WarrantyClaim::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:received,diagnosing,repairing,ready_for_pickup,completed,rejected',
            'diagnostic_notes' => 'nullable|string|max:2000',
        ]);

        $claim->update($validated);

        if ($request->wantsJson() || $request->ajax()) {
            return $this->successResponse($claim, 'Warranty RMA claim updated.');
        }

        return back()->with('success', 'Warranty RMA claim updated.');
    }
}
