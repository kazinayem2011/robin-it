<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariant;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService
    ) {}

    /**
     * Get a paginated list of products with optional filtering and sorting.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_slug' => 'nullable|string|max:120',
            'category_id' => 'nullable|integer',
            'brand_slug' => 'nullable|string|max:120',
            'brand_id' => 'nullable|integer',
            'brand_ids' => 'nullable|array|max:40',
            'brand_ids.*' => 'integer',
            'is_featured' => 'nullable|boolean',
            'in_stock' => 'nullable|boolean',
            'on_sale' => 'nullable|boolean',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'search' => 'nullable|string|max:120',
            'sort' => 'nullable|string|in:latest,price_low_high,price_high_low,name_asc,discount_high',
            'per_page' => 'nullable|integer|min:1|max:'.ProductService::MAX_PER_PAGE,
            'page' => 'nullable|integer|min:1',
        ]);

        $products = $this->productService->getFilteredProducts(
            $validated,
            $this->productService->clampPerPage($validated['per_page'] ?? null)
        );

        return $this->paginatedResponse(
            $products,
            'Products fetched successfully.',
            fn ($product) => $this->productService->formatProductCardData($product)
        );
    }

    /**
     * Which branches are holding something.
     *
     * Customers ring up to ask this constantly. Only branches that actually
     * have one are listed, and the online branch is excluded — that is the
     * warehouse the website already sells from, not somewhere to visit.
     */
    public function branchAvailability(Request $request, int $productId): JsonResponse
    {
        $validated = $request->validate([
            'variant_id' => 'nullable|integer',
        ]);

        $product = Product::active()->find($productId);

        if (! $product) {
            return $this->errorResponse('Product not found.', 404, ApiCode::NOT_FOUND);
        }

        $variant = ! empty($validated['variant_id'])
            ? ProductVariant::where('product_id', $product->id)->find($validated['variant_id'])
            : null;

        $rows = ProductStock::forUnit($product->id, $variant?->id)
            ->inStock()
            ->with('store:id,name,city,address,phone,is_active,holds_stock,fulfils_online')
            ->get()
            ->filter(fn ($row) => $row->store
                && $row->store->is_active
                && $row->store->holds_stock
                && ! $row->store->fulfils_online)
            ->sortBy(fn ($row) => $row->store->name)
            ->map(fn ($row) => [
                'store' => $row->store->name,
                'city' => $row->store->city,
                'address' => $row->store->address,
                'phone' => $row->store->phone,
                // Deliberately not the exact count: a showroom figure is a
                // day old the moment someone walks in with one.
                'available' => $row->quantity > 0,
            ])
            ->values();

        return $this->successResponse(
            ['branches' => $rows],
            'Branch availability fetched.'
        );
    }

    /**
     * The price range and brands present in a selection, so the filter sidebar
     * can draw itself from the real catalogue rather than hardcoded brackets.
     */
    public function filters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_slug' => 'nullable|string|max:120',
            'category_id' => 'nullable|integer',
            'brand_slug' => 'nullable|string|max:120',
            'brand_id' => 'nullable|integer',
            'brand_ids' => 'nullable|array|max:40',
            'brand_ids.*' => 'integer',
            'in_stock' => 'nullable|boolean',
            'on_sale' => 'nullable|boolean',
            'search' => 'nullable|string|max:120',
        ]);

        return $this->successResponse(
            $this->productService->getFilterFacets($validated),
            'Filters fetched successfully.'
        );
    }

    /**
     * Get Flash Sale products with discount calculations.
     */
    public function flashSale(): JsonResponse
    {
        return $this->successResponse(
            $this->productService->getFlashSaleProducts(),
            'Flash sale products retrieved successfully.'
        );
    }

    /**
     * Get Best Selling & Tabbed Featured Products.
     */
    public function featured(Request $request): JsonResponse
    {
        $tab = (string) $request->input('tab', 'all');

        return $this->successResponse(
            $this->productService->getFeaturedProducts($tab),
            'Featured products retrieved successfully.'
        );
    }

    /**
     * Get Dynamic Live Component Pricing for Interactive PC Builder Widget.
     */
    public function builderQuickSpecs(): JsonResponse
    {
        return $this->successResponse(
            $this->productService->getBuilderQuickSpecs(),
            'Builder quick specs fetched successfully.'
        );
    }

    /**
     * Get PC Builder blueprint categories.
     */
    public function pcBuilderCategories(): JsonResponse
    {
        return $this->successResponse(
            $this->productService->getPcBuilderCategories(),
            'PC builder categories fetched successfully.'
        );
    }

    /**
     * Get PC Builder selectable components for a specific category.
     */
    public function pcBuilderComponents(Request $request, string $categorySlug): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:120',
            // Current build, so each candidate can be checked against it.
            'selection' => 'nullable|array',
            'selection.*' => 'nullable|integer',
        ]);

        $selection = collect($validated['selection'] ?? [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $products = Product::whereIn('id', array_values($selection))
            ->with('specifications')
            ->get()
            ->keyBy('id');

        $resolved = [];
        foreach ($selection as $slot => $id) {
            if ($p = $products->get($id)) {
                $resolved[$slot] = $p;
            }
        }

        return $this->successResponse(
            $this->productService->getPcBuilderComponents(
                $categorySlug,
                $validated['search'] ?? null,
                $resolved
            ),
            'PC builder components fetched successfully.'
        );
    }

    /**
     * Get live instant search suggestions.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $query = (string) $request->input('q', '');

        return $this->successResponse(
            $this->productService->getSearchSuggestions($query),
            'Search suggestions retrieved successfully.'
        );
    }

    /**
     * Get a single product details.
     *
     * Only a genuinely missing product returns 404 — real faults are allowed to
     * surface as 500s rather than being disguised as "not found".
     */
    public function show(string $slug): JsonResponse
    {
        try {
            $product = $this->productService->getProductBySlug($slug);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Product not found.', 404, ApiCode::NOT_FOUND);
        }

        return $this->successResponse($product, 'Product details fetched successfully.');
    }
}
