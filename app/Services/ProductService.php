<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\SearchTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductService
{
    /** Default and hard-capped page sizes for the public catalogue. */
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 60;

    /** SQL for "the price the customer actually pays", matching Product::hasDiscount(). */
    private const EFFECTIVE_PRICE_SQL = 'CASE WHEN discount_price IS NOT NULL AND discount_price > 0 AND discount_price < price THEN discount_price ELSE price END';

    /**
     * How deep the discount is, as a percentage of the original price.
     *
     * Percentage rather than the amount saved, so a ৳900 saving on a ৳3,000
     * cooler outranks the same ৳900 off a ৳90,000 graphics card — which is the
     * one a shopper would call the better deal. Anything not discounted scores
     * zero and sinks to the bottom rather than being excluded, so this is a
     * safe sort for the full catalogue and not only the offers page.
     */
    private const DISCOUNT_DEPTH_SQL = 'CASE WHEN discount_price IS NOT NULL AND discount_price > 0 AND discount_price < price AND price > 0 THEN (price - discount_price) * 100.0 / price ELSE 0 END';

    public function __construct(
        protected CategoryService $categoryService,
        protected PcCompatibilityService $compatibility
    ) {}

    /**
     * Get paginated products based on query filters.
     */
    public function getFilteredProducts(array $filters, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $query = $this->baseFilteredQuery($filters)
            ->with(['brand', 'images', 'specifications', 'category'])
            ->withCatalogAggregates();

        // Sorting — price sorts use the discounted price the customer sees.
        $sort = $filters['sort'] ?? 'latest';
        match ($sort) {
            'price_low_high' => $query->orderByRaw(self::EFFECTIVE_PRICE_SQL.' asc'),
            'price_high_low' => $query->orderByRaw(self::EFFECTIVE_PRICE_SQL.' desc'),
            'name_asc' => $query->orderBy('name', 'asc'),
            // Ties broken by newest, so the order is stable across pages
            // rather than left to whatever the database returns.
            'discount_high' => $query->orderByRaw(self::DISCOUNT_DEPTH_SQL.' desc')
                ->orderByDesc('id'),
            default => $query->latest(),
        };

        return $query->paginate($this->clampPerPage($perPage))->withQueryString();
    }

    /**
     * Every filter except sorting and paging, shared by the listing and by the
     * facets that describe it — so a sidebar can never disagree with the
     * results it sits next to.
     */
    private function baseFilteredQuery(array $filters): Builder
    {
        $query = Product::active();

        // Filter by Category Slug or ID
        if (! empty($filters['category_slug'])) {
            $categoryIds = $this->categoryService->getDescendantIds($filters['category_slug']);
            $this->scopeToCategories($query, $categoryIds);
        } elseif (! empty($filters['category_id'])) {
            $categoryIds = $this->categoryService->getDescendantIds((int) $filters['category_id']);
            $this->scopeToCategories($query, $categoryIds);
        }

        // Filter by Brand. Several may be selected at once — a shopper
        // comparing ASUS against MSI should not have to pick one.
        if (! empty($filters['brand_ids'])) {
            $query->whereIn('brand_id', (array) $filters['brand_ids']);
        } elseif (! empty($filters['brand_slug'])) {
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

        // Only show what can actually be bought. On a variant product
        // stock_quantity is the maintained sum of its active options, so this
        // stays correct without joining them.
        if (! empty($filters['in_stock'])) {
            $query->where('stock_quantity', '>', 0);
        }

        // Price range, measured against the price the customer actually pays
        // rather than the list price — otherwise a heavily discounted card is
        // filtered out of the bracket it visibly sits in.
        // The `+ 0` is load-bearing on SQLite. PDO binds these as text, and a
        // CASE expression — unlike a column — carries no type affinity, so
        // SQLite compares storage classes instead of values and rules every
        // number lower than every string: `50000 <= '100'` came out true.
        // Adding zero forces numeric context, and is a no-op on MySQL.
        if (isset($filters['min_price']) && $filters['min_price'] !== '' && $filters['min_price'] !== null) {
            $query->whereRaw(self::EFFECTIVE_PRICE_SQL.' >= (? + 0)', [(float) $filters['min_price']]);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== '' && $filters['max_price'] !== null) {
            $query->whereRaw(self::EFFECTIVE_PRICE_SQL.' <= (? + 0)', [(float) $filters['max_price']]);
        }

        // Genuinely discounted right now — the same rule the badge uses.
        if (! empty($filters['on_sale'])) {
            $query->discounted();
        }

        // Keyword Search
        if (! empty($filters['search'])) {
            $search = SearchTerm::escape(trim($filters['search']));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('short_description', 'LIKE', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * What the filter sidebar needs to draw itself: the price range actually
     * present in this selection, and the brands within it.
     *
     * Deliberately ignores the shopper's own price bounds — a slider whose ends
     * move every time it is dragged is unusable — but respects category, brand,
     * search and stock, so the range always reflects a real set of products.
     *
     * @return array{min_price: float, max_price: float, brands: array, total: int}
     */
    public function getFilterFacets(array $filters): array
    {
        $scoped = array_diff_key($filters, array_flip([
            'min_price', 'max_price', 'sort', 'page', 'per_page',
        ]));

        $query = $this->baseFilteredQuery($scoped);

        // The brand list is built without the brand filter applied: narrowing
        // it to the brand already chosen would leave nothing else to pick.
        $brandScope = array_diff_key($scoped, array_flip([
            'brand_ids', 'brand_id', 'brand_slug',
        ]));

        $bounds = (clone $query)
            ->selectRaw('MIN('.self::EFFECTIVE_PRICE_SQL.') as min_price')
            ->selectRaw('MAX('.self::EFFECTIVE_PRICE_SQL.') as max_price')
            ->reorder()
            ->first();

        $brands = $this->baseFilteredQuery($brandScope)
            ->reorder()
            ->with('brand:id,name,slug')
            ->get(['id', 'brand_id'])
            ->pluck('brand')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'slug' => $b->slug])
            ->all();

        // The category being browsed, so the page can title itself with the
        // catalogue's own name. Deriving it from the slug produced "Pc Case"
        // where the shop says "PC Case / Chassis", and taking it from the first
        // product gave that product's leaf sub-category instead.
        $category = null;

        if (! empty($filters['category_slug'])) {
            $category = Category::where('slug', $filters['category_slug'])
                ->where('is_active', true)
                ->first(['id', 'name', 'slug']);
        } elseif (! empty($filters['category_id'])) {
            $category = Category::where('is_active', true)
                ->find($filters['category_id'], ['id', 'name', 'slug']);
        }

        return [
            'min_price' => (float) ($bounds->min_price ?? 0),
            'max_price' => (float) ($bounds->max_price ?? 0),
            'brands' => $brands,
            'categories' => $this->categoryFacet($scoped),
            'total' => (clone $query)->reorder()->count(),
            'category' => $category ? [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ] : null,
        ];
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
                $this->scopeToCategories($query, $allCatIds);
            }
        }

        return $query->take($this->clampLimit($limit))
            ->get()
            ->map(fn (Product $p) => $this->formatProductCardData($p));
    }

    /**
     * The categories a shopper can still narrow to, as a two-level tree with
     * counts.
     *
     * Built without the category filter applied, for the same reason the brand
     * list is: narrowing it to the category already chosen would leave nothing
     * else to pick and the sidebar would be a dead end.
     *
     * Products hang off leaf categories, so the counts are rolled up to the
     * parents — a top-level entry reads as everything beneath it, which is what
     * its own link actually returns.
     *
     * @return array<int, array<string, mixed>>
     */
    private function categoryFacet(array $scoped): array
    {
        $scope = array_diff_key($scoped, array_flip(['category_slug', 'category_id']));

        $counts = $this->baseFilteredQuery($scope)
            ->reorder()
            ->join('category_product', 'category_product.product_id', '=', 'products.id')
            ->selectRaw('category_product.category_id as category_id, COUNT(DISTINCT products.id) as total')
            ->groupBy('category_product.category_id')
            ->pluck('total', 'category_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $categories = Category::where('is_active', true)
            ->get(['id', 'name', 'slug', 'parent_id'])
            ->keyBy('id');

        // Roll each leaf's count up through its ancestors.
        $totals = [];

        foreach ($counts as $categoryId => $total) {
            $node = $categories->get($categoryId);
            $guard = 0;

            while ($node && $guard++ < 10) {
                $totals[$node->id] = ($totals[$node->id] ?? 0) + (int) $total;
                $node = $node->parent_id ? $categories->get($node->parent_id) : null;
            }
        }

        $node = fn (Category $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'count' => $totals[$c->id] ?? 0,
        ];

        return $categories
            ->whereNull('parent_id')
            ->filter(fn ($c) => ($totals[$c->id] ?? 0) > 0)
            ->sortBy('name')
            ->map(function (Category $parent) use ($categories, $totals, $node) {
                return $node($parent) + [
                    'children' => $categories
                        ->where('parent_id', $parent->id)
                        ->filter(fn ($c) => ($totals[$c->id] ?? 0) > 0)
                        ->sortBy('name')
                        ->map($node)
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
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
            'image' => $product->images->first()?->image_url ?: ProductImage::PLACEHOLDER,
            'rating' => $rating,
            'reviews' => $reviewCount,
            'sold' => $sold,
            'totalStock' => $stock + $sold,
            'inStock' => $product->isInStock(),
            'stockQuantity' => $stock,
            // Sold ahead of a delivery. `preorder` is only true when the shelf
            // is empty and the setting is on, so the UI never has to work out
            // which of the two states it is looking at.
            'allowsPreorder' => $product->allowsPreorder(),
            'preorder' => $product->allowsPreorder() && ! $product->isInStock(),
            'preorderReleaseAt' => $product->preorder_release_at?->toDateString(),
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
            ->with([
                'category', 'brand', 'images', 'specifications',
                // Stock and price live on the option for a variant product, so
                // the detail page cannot render a buy button without them.
                'activeVariants',
                // Hand-picked suggestions. Constrained to what can actually be
                // bought: a sidebar of dead links loses more trust than an
                // empty sidebar.
                'relatedProducts' => fn ($q) => $q->where('products.is_active', true)
                    ->with('images')
                    ->take(6),
            ])
            ->withCatalogAggregates()
            ->firstOrFail();
    }

    /**
     * Get live dynamic component specs for interactive PC Builder Widget directly from DB.
     */
    public function getBuilderQuickSpecs(): array
    {
        $cpus = $this->componentsUnder('cpu');
        $gpus = $this->componentsUnder('gpu', 'graphics-card');
        $rams = $this->componentsUnder('ram', 'memory');

        return [
            'cpu' => $this->formatSpecGroup($cpus, 125),
            'gpu' => $this->formatSpecGroup($gpus, 250),
            'ram' => $this->formatSpecGroup($rams, 15),
        ];
    }

    /**
     * Everything filed anywhere under a section of the catalogue.
     *
     * This matched the product's own category slug against '%cpu%' before, so
     * it found only products filed directly on a category whose slug happened
     * to contain the word. Every processor in the shop sits two levels down
     * under "intel-core-i9-14th" or "amd-ryzen-7-x3d" — none of which say
     * "cpu" — so Step 1 of the homepage builder was empty while Steps 2 and 3
     * worked by luck: '%rtx%' and '%ddr%' happened to match the graphics and
     * memory sub-categories.
     *
     * The tree is what decides, so a section added tomorrow is included
     * without anyone remembering to name it here.
     */
    private function componentsUnder(string ...$rootSlugs): Collection
    {
        $ids = $this->categoryTreeIds($rootSlugs);

        if ($ids === []) {
            return collect();
        }

        return Product::active()
            ->whereIn('category_id', $ids)
            ->with(['specifications'])
            ->get();
    }

    /**
     * A category and everything beneath it, however deep.
     *
     * @param  array<int, string>  $rootSlugs
     * @return array<int, int>
     */
    private function categoryTreeIds(array $rootSlugs): array
    {
        $ids = Category::whereIn('slug', $rootSlugs)->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $frontier = $ids;

        // Bounded rather than recursive: a cycle in parent_id would otherwise
        // spin here forever, and no catalogue is ten levels deep.
        for ($depth = 0; $depth < 10 && $frontier !== []; $depth++) {
            $frontier = Category::whereIn('parent_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->all();

            $ids = array_merge($ids, $frontier);
        }

        return $ids;
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
    /**
     * The slots a build is assembled from.
     *
     * Declared explicitly rather than read off whatever categories happen to
     * exist, because order and grouping are the whole point: someone building a
     * machine works down from the processor, and peripherals are a separate
     * decision from the parts that have to fit together. An optional slot with
     * nothing to put in it is dropped; a required one is kept and marked
     * unavailable, because hiding it would make a build that cannot be
     * completed look as though it could.
     *
     * @return array<int, array>
     */
    public function getPcBuilderCategories(): array
    {
        $slots = [
            // Core: the parts that have to be compatible with each other.
            ['slug' => 'cpu', 'icon' => 'Cpu', 'group' => 'core', 'required' => true,
                'hint' => 'Sets the socket your motherboard must match'],
            ['slug' => 'cpu-cooler', 'icon' => 'Wind', 'group' => 'core', 'required' => false,
                'hint' => 'Some processors include one'],
            ['slug' => 'motherboard', 'icon' => 'Server', 'group' => 'core', 'required' => true,
                'hint' => 'Must match the processor socket and memory type'],
            ['slug' => 'ram', 'icon' => 'Layers', 'group' => 'core', 'required' => true,
                'hint' => 'DDR4 and DDR5 are not interchangeable'],
            ['slug' => 'storage', 'icon' => 'HardDrive', 'group' => 'core', 'required' => true,
                'hint' => 'Where Windows and your games live'],
            ['slug' => 'graphics-card', 'icon' => 'Monitor', 'group' => 'core', 'required' => false,
                'hint' => 'Not needed if the processor has graphics built in'],
            ['slug' => 'power-supply', 'icon' => 'Zap', 'group' => 'core', 'required' => true,
                'hint' => 'Sized against the wattage shown above'],
            ['slug' => 'pc-case', 'icon' => 'Box', 'group' => 'core', 'required' => true,
                'hint' => 'Must fit the motherboard form factor'],

            // Peripherals: chosen freely, nothing here has to fit anything.
            ['slug' => 'monitors', 'icon' => 'Tv', 'group' => 'peripherals', 'required' => false],
            ['slug' => 'keyboards', 'icon' => 'Keyboard', 'group' => 'peripherals', 'required' => false],
            ['slug' => 'mice', 'icon' => 'Mouse', 'group' => 'peripherals', 'required' => false],
            ['slug' => 'headsets', 'icon' => 'Headphones', 'group' => 'peripherals', 'required' => false],
            ['slug' => 'wifi-routers', 'icon' => 'Wifi', 'group' => 'peripherals', 'required' => false],
        ];

        $categories = Category::where('is_active', true)
            ->whereIn('slug', array_column($slots, 'slug'))
            ->get()
            ->keyBy('slug');

        // Resolving descendants and counting stock per slot used to be done
        // inside the loop, which was 53 queries for 13 slots — each one walking
        // the category tree again and running its own count. Both are done once
        // here and read from memory below.
        $descendants = $this->descendantMap($categories->pluck('id')->all());
        $counts = Product::where('is_active', true)
            ->selectRaw('category_id, COUNT(*) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        return collect($slots)
            ->map(function (array $slot) use ($categories, $descendants, $counts) {
                $category = $categories->get($slot['slug']);

                if (! $category) {
                    return null;
                }

                $ids = $descendants[$category->id] ?? [$category->id];
                $available = collect($ids)->sum(fn ($id) => (int) ($counts[$id] ?? 0));

                // An optional slot with nothing behind it is a dead end and is
                // dropped. A required one is kept and marked unavailable —
                // hiding it would make a build look completable when it is not.
                if ($available === 0 && ! $slot['required']) {
                    return null;
                }

                return [
                    'id' => $slot['slug'],
                    'category_id' => $category->id,
                    'name' => $category->name,
                    'category_slug' => $slot['slug'],
                    'required' => $slot['required'],
                    'group' => $slot['group'],
                    'icon' => $slot['icon'],
                    // A short reason this slot matters, rather than the same
                    // "genuine product with warranty" line on every row.
                    'hint' => $slot['hint'] ?? null,
                    'available' => $available,
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Every category id beneath each of the given roots, resolved in one pass.
     *
     * The whole tree is read once and walked in memory. Asking the database per
     * root re-reads the same rows over and over — thirteen slots turned into
     * fifty-three queries.
     *
     * @param  array<int, int>  $rootIds
     * @return array<int, array<int, int>>
     */
    private function descendantMap(array $rootIds): array
    {
        if ($rootIds === []) {
            return [];
        }

        $childrenByParent = Category::where('is_active', true)
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id')
            ->map(fn ($rows) => $rows->pluck('id')->all())
            ->all();

        $collect = function (int $id) use (&$collect, $childrenByParent): array {
            $ids = [$id];

            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $ids = array_merge($ids, $collect($childId));
            }

            return $ids;
        };

        $map = [];

        foreach ($rootIds as $rootId) {
            $map[$rootId] = $collect($rootId);
        }

        return $map;
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
            $needle = SearchTerm::escape($componentSlug);
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'LIKE', "%{$needle}%")
                    ->orWhere('short_description', 'LIKE', "%{$needle}%");
            });
        }

        if (! empty($search)) {
            $needle = SearchTerm::escape(trim($search));
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

        $needle = SearchTerm::escape($term);

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

    private function clampLimit(int $limit): int
    {
        return max(1, min($limit, self::MAX_PER_PAGE));
    }

    /**
     * Narrow a product query to a set of categories, through the pivot.
     *
     * whereHas rather than a join: a product listed under both "Gaming Laptop >
     * Asus" and "All Laptop > Asus" matches two pivot rows, and a join would
     * return it twice — once as a duplicate card in the grid, and again as a
     * doubled figure in every count built on the same query.
     *
     * @param  array<int, int>  $categoryIds
     */
    private function scopeToCategories($query, array $categoryIds): void
    {
        if (empty($categoryIds)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas(
            'categories',
            fn ($q) => $q->whereIn('categories.id', $categoryIds)
        );
    }
}
