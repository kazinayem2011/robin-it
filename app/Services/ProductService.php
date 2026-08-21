<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductService
{
    /** Default and hard-capped page sizes for the public catalogue. */
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 60;

    /** SQL for "the price the customer actually pays", matching Product::hasDiscount(). */
    private const EFFECTIVE_PRICE_SQL = 'CASE WHEN discount_price IS NOT NULL AND discount_price > 0 AND discount_price < price THEN discount_price ELSE price END';

    public function __construct(
        protected CategoryService $categoryService,
        protected PcCompatibilityService $compatibility
    ) {}

    /**
     * Get paginated products based on query filters.
     */
    public function getFilteredProducts(array $filters, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $query = Product::active()
            ->with(['brand', 'images', 'specifications', 'category'])
            ->withCatalogAggregates();

        // Filter by Category Slug or ID
        if (! empty($filters['category_slug'])) {
            $categoryIds = $this->categoryService->getDescendantIds($filters['category_slug']);
            $query->whereIn('category_id', $categoryIds ?: [0]);
        } elseif (! empty($filters['category_id'])) {
            $categoryIds = $this->categoryService->getDescendantIds((int) $filters['category_id']);
            $query->whereIn('category_id', $categoryIds ?: [0]);
        }

        // Filter by Brand
        if (! empty($filters['brand_slug'])) {
            $brandSlug = $filters['brand_slug'];
            $query->whereHas('brand', function ($q) use ($brandSlug) {
                $q->where('slug', $brandSlug);
            });
        } elseif (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        // Filter by Featured
        if (! empty($filters['is_featured'])) {
            $query->where('is_featured', true);
        }

        // Only show what can actually be bought
        if (! empty($filters['in_stock'])) {
            $query->where('stock_quantity', '>', 0);
        }

        // Keyword Search
        if (! empty($filters['search'])) {
            $search = $this->escapeLike(trim($filters['search']));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('short_description', 'LIKE', "%{$search}%");
            });
        }

        // Sorting — price sorts use the discounted price the customer sees.
        $sort = $filters['sort'] ?? 'latest';
        match ($sort) {
            'price_low_high' => $query->orderByRaw(self::EFFECTIVE_PRICE_SQL.' asc'),
            'price_high_low' => $query->orderByRaw(self::EFFECTIVE_PRICE_SQL.' desc'),
            'name_asc' => $query->orderBy('name', 'asc'),
            default => $query->latest(),
        };

        return $query->paginate($this->clampPerPage($perPage))->withQueryString();
    }

    /**
     * Clamp a client-supplied page size so `?per_page=500000` can't be used to
     * pull the whole catalogue (and four eager-loaded relations) in one request.
     */
    public function clampPerPage(int|string|null $perPage): int
    {
        $perPage = (int) $perPage;

        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    /**
     * Get Flash Sale products.
     */
    public function getFlashSaleProducts(int $limit = 8): Collection
    {
        return Product::active()
            ->discounted()
            ->with(['brand', 'images', 'specifications', 'category'])
            ->withCatalogAggregates()
            ->take($this->clampLimit($limit))
            ->get()
            ->map(fn (Product $p) => $this->formatProductCardData($p, isFlashSale: true));
    }

    /**
     * Get Best Selling & Tabbed Featured Products.
     */
    public function getFeaturedProducts(string $tab = 'all', int $limit = 8): Collection
    {
        $query = Product::active()
            ->with(['brand', 'images', 'specifications', 'category'])
            ->withCatalogAggregates();

        if ($tab !== 'all') {
            $tabSlug = $tab === 'gpu' ? 'graphics-card' : $tab;
            $allCatIds = $this->categoryService->getDescendantIds($tabSlug);

            if (! empty($allCatIds)) {
                $query->whereIn('category_id', $allCatIds);
            }
        }

        return $query->take($this->clampLimit($limit))
            ->get()
            ->map(fn (Product $p) => $this->formatProductCardData($p));
    }

    /**
     * Format a product instance into standardized frontend UI card format.
     *
     * Ratings, review counts and units sold are real values — a brand-new product
     * reports zeroes so the UI can say "New" instead of inventing social proof.
     */
    public function formatProductCardData(Product $product, bool $isFlashSale = false): array
    {
        $hasDiscount = $product->hasDiscount();
        $currentPrice = $product->effective_price;
        $saving = $product->saving;
        $discountPct = $hasDiscount && $product->price > 0 ? (int) round(($saving / $product->price) * 100) : 0;

        $stock = max(0, (int) $product->stock_quantity);
        $sold = (int) ($product->sold_count ?? 0);
        $reviewCount = (int) ($product->approved_reviews_count ?? 0);
        $rating = $product->approved_rating_avg !== null
            ? round((float) $product->approved_rating_avg, 1)
            : 0.0;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'brand' => $product->brand ? $product->brand->name : 'Robins Computer',
            'category' => $product->category ? $product->category->name : 'Hardware',
            'price' => '৳'.number_format($currentPrice),
            'raw_price' => (float) $currentPrice,
            'oldPrice' => $hasDiscount ? '৳'.number_format($product->price) : null,
            'raw_old_price' => (float) $product->price,
            'save' => $saving > 0 ? 'SAVE ৳'.number_format($saving) : null,
            'discount' => $hasDiscount ? '-'.$discountPct.'%' : null,
            'isFlashSale' => $isFlashSale,
            'image' => $product->images->first()?->image_path ?: '/images/product_cpu_i9.jpg',
            'rating' => $rating,
            'reviews' => $reviewCount,
            'sold' => $sold,
            'totalStock' => $stock + $sold,
            'inStock' => $product->isInStock(),
            'stockQuantity' => $stock,
            'wattage' => $product->estimatedWattage(),
            'specs' => $product->specifications->take(3)->map(function ($s) {
                return $s->name.': '.$s->value;
            })->values()->toArray() ?: [
                $product->short_description ?: 'Official Global Warranty',
            ],
        ];
    }

    /**
     * Get single product by slug with relations.
     */
    public function getProductBySlug(string $slug): Product
    {
        return Product::active()
            ->where('slug', $slug)
            ->with(['category', 'brand', 'images', 'specifications'])
            ->withCatalogAggregates()
            ->firstOrFail();
    }

    /**
     * Get live dynamic component specs for interactive PC Builder Widget directly from DB.
     */
    public function getBuilderQuickSpecs(): array
    {
        $cpus = $this->componentsMatchingSlugs(['%cpu%', '%processor%']);
        $gpus = $this->componentsMatchingSlugs(['%gpu%', '%graphics%', '%rtx%']);
        $rams = $this->componentsMatchingSlugs(['%ram%', '%ddr%']);

        return [
            'cpu' => $this->formatSpecGroup($cpus, 125),
            'gpu' => $this->formatSpecGroup($gpus, 250),
            'ram' => $this->formatSpecGroup($rams, 15),
        ];
    }

    /**
     * @param  array<int, string>  $slugPatterns
     */
    private function componentsMatchingSlugs(array $slugPatterns): Collection
    {
        return Product::active()
            ->whereHas('category', function ($q) use ($slugPatterns) {
                $q->where(function ($inner) use ($slugPatterns) {
                    foreach ($slugPatterns as $pattern) {
                        $inner->orWhere('slug', 'like', $pattern);
                    }
                });
            })
            ->with(['specifications'])
            ->get();
    }

    /**
     * Turn a group of components into the builder's keyed price/wattage map.
     *
     * The spec columns are `name`/`value` — reading `spec_name`/`spec_value` silently
     * returned null, so every component fell back to the default wattage and the
     * PSU recommendation was meaningless.
     */
    private function formatSpecGroup(Collection $products, int $defaultWattage): array
    {
        $formatted = [];

        foreach ($products as $p) {
            $key = Str::slug($p->slug);

            $formatted[$key] = [
                'id' => $p->id,
                'name' => $p->name,
                'price' => $p->effective_price,
                'wattage' => $p->estimatedWattage($defaultWattage),
                'inStock' => $p->isInStock(),
            ];
        }

        return $formatted;
    }

    /**
     * Get PC Builder dynamic categories directly querying from the Database.
     */
    public function getPcBuilderCategories(): array
    {
        // 1. Check if 'Components' root category exists in DB
        $componentsRoot = Category::where('slug', 'components')
            ->orWhere('name', 'like', '%Components%')
            ->first();

        // 2. Fetch categories from DB
        $categoriesQuery = Category::where('is_active', true);
        if ($componentsRoot) {
            $categoriesQuery->where('parent_id', $componentsRoot->id);
        } else {
            $categoriesQuery->whereIn('slug', ['cpu', 'graphics-card', 'motherboard', 'ram', 'storage', 'power-supply', 'casing', 'monitors']);
        }

        $dbCategories = $categoriesQuery->orderBy('id', 'asc')->get();

        $iconMap = [
            'cpu' => 'Cpu',
            'processor' => 'Cpu',
            'motherboard' => 'Server',
            'ram' => 'Layers',
            'storage' => 'HardDrive',
            'graphics-card' => 'Monitor',
            'power-supply' => 'Zap',
            'casing' => 'Box',
            'cpu-cooler' => 'Wind',
            'monitors' => 'Tv',
        ];

        return $dbCategories->map(function (Category $cat) use ($iconMap) {
            $slug = $cat->slug;
            $icon = $iconMap[$slug] ?? ($cat->icon ?: 'Box');
            $isRequired = in_array($slug, ['cpu', 'processor', 'motherboard', 'ram', 'storage', 'power-supply', 'casing']);

            return [
                'id' => $slug,
                'category_id' => $cat->id,
                'name' => $cat->name,
                'category_slug' => $slug,
                'required' => $isRequired,
                'icon' => $icon,
                'description' => "Genuine {$cat->name} with authorized manufacturer warranty",
            ];
        })->values()->toArray();
    }

    /**
     * Get PC Builder selectable components for a specific category.
     *
     * @param  array<string, Product>  $selection  current build, for compatibility annotation
     */
    public function getPcBuilderComponents(
        string $componentSlug,
        ?string $search = null,
        array $selection = []
    ): Collection {
        $categoryIds = $this->categoryService->getDescendantIds($componentSlug);

        $query = Product::active()
            ->with(['brand', 'images', 'specifications', 'category'])
            ->withCatalogAggregates();

        if (! empty($categoryIds)) {
            $query->whereIn('category_id', $categoryIds);
        } else {
            // Fallback search if category slug has no direct match
            $needle = $this->escapeLike($componentSlug);
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'LIKE', "%{$needle}%")
                    ->orWhere('short_description', 'LIKE', "%{$needle}%");
            });
        }

        if (! empty($search)) {
            $needle = $this->escapeLike(trim($search));
            $query->where('name', 'LIKE', "%{$needle}%");
        }

        $products = $query->latest()->take(self::MAX_PER_PAGE)->get();

        // Annotate against the current build so the picker can show what fits.
        $annotated = $this->compatibility->annotateCandidates($componentSlug, $products, $selection);

        return $annotated->map(fn (array $entry) => array_merge(
            $this->formatProductCardData($entry['product']),
            ['compatibility' => [
                'status' => $entry['status'],
                'reason' => $entry['reason'],
            ]]
        ))->values();
    }

    /**
     * Get live instant search suggestions (Products, Categories, Brands).
     */
    public function getSearchSuggestions(string $query): array
    {
        $term = trim($query);

        if (mb_strlen($term) < 2) {
            return [
                'products' => [],
                'categories' => [],
                'brands' => [],
            ];
        }

        $needle = $this->escapeLike($term);

        $products = Product::active()
            ->where(function ($q) use ($needle) {
                $q->where('name', 'LIKE', "%{$needle}%")
                    ->orWhere('slug', 'LIKE', "%{$needle}%")
                    ->orWhere('short_description', 'LIKE', "%{$needle}%");
            })
            ->with(['brand', 'images', 'specifications', 'category'])
            ->withCatalogAggregates()
            ->take(6)
            ->get()
            ->map(fn (Product $p) => $this->formatProductCardData($p));

        $categories = Category::where('is_active', true)
            ->where('name', 'LIKE', "%{$needle}%")
            ->take(4)
            ->get(['id', 'name', 'slug', 'icon']);

        $brands = Brand::where('name', 'LIKE', "%{$needle}%")
            ->take(4)
            ->get(['id', 'name', 'slug', 'logo_path']);

        return [
            'products' => $products,
            'categories' => $categories,
            'brands' => $brands,
        ];
    }

    /**
     * Escape LIKE wildcards so a search for "100%" doesn't match the entire catalogue.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    private function clampLimit(int $limit): int
    {
        return max(1, min($limit, self::MAX_PER_PAGE));
    }
}
