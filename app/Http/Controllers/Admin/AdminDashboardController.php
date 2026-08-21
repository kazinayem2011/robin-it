<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Mail\OrderStatusUpdatedMail;
use App\Models\Banner;
use App\Models\BlogPost;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\Store;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\OrderService;
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

        $query = Product::with(['category', 'brand', 'images'])->latest();

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
    public function updateProduct(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);

        $validated = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'name' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'discount_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'image_path' => 'nullable|string',
        ]);

        // A posted `null` must not blank out a NOT NULL column such as name or price.
        $product->update(array_filter(
            $validated,
            fn ($value, $key) => $value !== null || in_array($key, ['brand_id', 'discount_price'], true),
            ARRAY_FILTER_USE_BOTH
        ));

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
     * Create a New Product.
     */
    public function storeProduct(Request $request)
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
        ]);

        $validated['slug'] = $this->uniqueSlug(Product::class, $validated['name']);
        $validated['is_active'] = true;

        $product = Product::create($validated);

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
        $coupons = Coupon::latest()->get();

        return Inertia::render('Admin/Coupons', [
            'coupons' => $coupons,
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
            'expires_at' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        $coupon = Coupon::create($validated);

        if ($request->wantsJson() || $request->ajax()) {
            return $this->successResponse($coupon, 'Coupon created.', 201);
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
            'expires_at' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        $coupon->update($validated);

        if ($request->wantsJson() || $request->ajax()) {
            return $this->successResponse($coupon, 'Coupon updated successfully.');
        }

        return back()->with('success', 'Coupon updated successfully.');
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
            'is_active' => 'boolean',
        ]);

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
            'is_active' => 'boolean',
        ]);

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
        $settings = SiteSetting::all();

        return Inertia::render('Admin/Settings', [
            'settings' => $settings,
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
            SiteSetting::updateOrCreate(
                ['key' => (string) $key],
                ['value' => is_bool($val) ? ($val ? '1' : '0') : (string) $val]
            );
        }

        SiteSetting::flushCache(array_keys($data['settings']));

        if ($request->wantsJson() || $request->ajax()) {
            return $this->successResponse([], 'Settings saved successfully.');
        }

        return back()->with('success', 'Settings updated successfully.');
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
