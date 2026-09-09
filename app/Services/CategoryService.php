<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CategoryService
{
    /**
     * The mega menu is rebuilt on every page the header renders, which is every
     * page — a read of the whole category table, a distinct scan of products,
     * and a tree walk, per visitor per navigation. It changes when an admin
     * edits the catalogue and at no other time, so it is cached until then.
     */
    public const MEGA_MENU_KEY = 'catalog.mega_menu';

    public const FEATURED_KEY = 'catalog.featured_categories';

    /** Long, because every write path invalidates explicitly. */
    private const TTL = 21600;

    /**
     * Drop the cached catalogue views.
     *
     * Wired to Category and Product model events in AppServiceProvider, so it
     * covers seeders, tinker and any future writer as well as the admin screens.
     */
    public static function flush(): void
    {
        Cache::forget(self::MEGA_MENU_KEY);
        Cache::forget(self::FEATURED_KEY);
    }

    /**
     * Get the nested category tree for the Mega Menu.
     *
     * Categories holding nothing are left out. Three of the nine top-level
     * entries had no products anywhere beneath them — Accessories, Server &
     * Storage and Offers & Deals — so a third of the main navigation led
     * straight to "No products found". They come back on their own as soon as
     * the shop stocks them.
     *
     * An offer category is the exception: its discounts live on the products,
     * not on a category assignment, so it never has any of its own.
     */
    public function getMegaMenuTree(): Collection
    {
        /*
         * Plain arrays go into the cache, never objects.
         *
         * config/cache.php sets `serializable_classes => false`, which is
         * Laravel's secure default: nothing read back out of the cache may
         * reconstruct a PHP class. Caching the Collection this used to return
         * meant every cache *hit* came back as __PHP_Incomplete_Class and threw
         * — while every cache miss worked, so it only failed on the second
         * request. An array survives any driver.
         */
        $tree = Cache::remember(
            self::MEGA_MENU_KEY,
            self::TTL,
            fn () => $this->buildMegaMenuTree()
        );

        return collect($tree);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMegaMenuTree(): array
    {
        $stocked = $this->categoryIdsWithProducts();

        /*
         * Third-level entries are overwhelmingly brand names, and a drawn icon
         * cannot say "ASUS" — a generic box next to every one of eleven hundred
         * brands is noise pretending to be information. A shelf that stands for
         * a brand carries its logo; everything else falls back to a lettermark
         * in the interface.
         *
         * Two ways of finding the brand, and the order matters. The shelf's own
         * brand_id is the authority, because names are not a join: it left 44
         * of the 144 brand shelves without a logo, cannot connect a shelf named
         * "ASUS" to the brand row "ASUS (Network)", and comes apart the moment
         * either side is renamed.
         *
         * The name is still tried when no brand is set, because a shop that
         * creates a shelf called "AMD" and uploads an AMD logo expects the two
         * to meet without being told to link them.
         *
         * Loaded once here rather than per node: the tree is built behind a
         * cache, so this is one query per rebuild, not per visitor.
         */
        $brandLogos = Brand::whereNotNull('logo_path')
            ->pluck('logo_path', 'name')
            ->mapWithKeys(fn ($path, $name) => [mb_strtolower(trim($name)) => $path]);

        return Category::whereNull('parent_id')
            ->inMenuOrder()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('is_offer', true)
                ->orWhereIn('id', $stocked))
            ->with(['children' => function ($query) use ($stocked) {
                $query->where('is_active', true)
                    ->whereIn('id', $stocked)
                    ->with(['children' => function ($q) use ($stocked) {
                        $q->where('is_active', true)
                            ->whereIn('id', $stocked)
                            // One query for every brand on the tree, not one per shelf.
                            ->with('brand:id,name,logo_path');
                    }]);
            }])
            ->get()
            ->map(function (Category $cat) use ($brandLogos) {
                return [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                    'badge' => $cat->badge,
                    'icon' => $cat->icon,
                    'isOffer' => (bool) $cat->is_offer,
                    'subcategories' => $cat->children->map(function ($sub) use ($brandLogos) {
                        return [
                            'id' => $sub->id,
                            'name' => $sub->name,
                            'slug' => $sub->slug,
                            'icon' => $sub->icon,
                            'children' => $sub->children->map(function ($child) use ($brandLogos) {
                                return [
                                    'id' => $child->id,
                                    'name' => $child->name,
                                    'slug' => $child->slug,
                                    'logo' => $child->brand?->logo_path
                                        ?? $brandLogos->get(mb_strtolower(trim($child->name))),
                                    'isHot' => str_contains(strtolower($child->name), '4090')
                                        || str_contains(strtolower($child->name), '5090')
                                        || str_contains(strtolower($child->name), 'ultra beast'),
                                ];
                            })->all(),
                        ];
                    })->all(),
                    'promoBanner' => [
                        'title' => $cat->spotlight_title ?: ($cat->name.' Collection'),
                        'subtitle' => $cat->spotlight_subtitle ?: 'Official 100% Genuine Tech with Warranty',
                        'link' => $cat->spotlight_link ?: ('/shop/'.$cat->slug),
                        'image' => $cat->spotlight_image ?: (match ($cat->slug) {
                            'laptops' => '/images/slider_laptop.jpg',
                            'monitors' => '/images/promo_creator.jpg',
                            'gaming-gear' => '/images/promo_gpu.jpg',
                            'desktops' => '/images/slider_gaming_pc.jpg',
                            default => '/images/slider_gaming_pc.jpg',
                        }),
                    ],
                ];
            })
            ->all();
    }

    /**
     * Every category id that has a product somewhere beneath it.
     *
     * Two queries regardless of depth: the products' own categories, then the
     * count rolled up through the parent chain, so a top-level entry counts as
     * stocked when only a grandchild holds anything.
     *
     * @return array<int, int>
     */
    private function categoryIdsWithProducts(): array
    {
        // Read through the pivot: a product listed in several categories has
        // to make every one of them visible, not just its primary.
        $rows = DB::table('category_product')
            ->join('products', 'products.id', '=', 'category_product.product_id')
            ->where('products.is_active', true)
            ->distinct()
            ->get(['category_product.category_id', 'products.brand_id']);

        if ($rows->isEmpty()) {
            return [];
        }

        $parents = Category::pluck('parent_id', 'id');
        $stocked = [];

        // Which makers have something on each shelf, ancestors included.
        $makersOn = [];

        foreach ($rows as $row) {
            if (! $row->category_id) {
                continue;
            }

            $stocked[$row->category_id] = true;

            if ($row->brand_id) {
                $makersOn[$row->category_id][$row->brand_id] = true;
            }

            $cursor = $parents[$row->category_id] ?? null;

            // Walk up to the root. The guard is against a cycle in the data,
            // which would otherwise hang the request.
            for ($depth = 0; $cursor !== null && $depth < 10; $depth++) {
                $stocked[$cursor] = true;

                if ($row->brand_id) {
                    $makersOn[$cursor][$row->brand_id] = true;
                }

                $cursor = $parents[$cursor] ?? null;
            }
        }

        /*
         * A brand shelf holds what its maker made on the shelf above, and it
         * holds it without a pivot row — that is the point of it. Counting
         * only pivot rows would call it empty and drop it from the menu, so
         * the one page that fills itself would be the one nobody could reach.
         */
        foreach (Category::whereNotNull('brand_id')->get(['id', 'parent_id', 'brand_id']) as $shelf) {
            if ($shelf->parent_id && isset($makersOn[$shelf->parent_id][$shelf->brand_id])) {
                $stocked[$shelf->id] = true;
            }
        }

        return array_keys($stocked);
    }

    /**
     * The makers on a shelf, as the shelves that stand for them.
     *
     * Shown as a row across the top of a category page, which is how the trade
     * presents it — Star Tech puts Lenovo, MSI, HP, Asus in a line under the
     * heading, each one a page of its own, before any filter is touched. It is
     * the shortest route a shopper has: most people arriving at Laptop already
     * know whose laptop they want.
     *
     * Distinct by maker, not by shelf. ASUS can stand under both All Laptop
     * and Gaming Laptop within the same department, and the row wants one ASUS
     * — the nearest one, so the link stays as close to where the shopper is as
     * it can.
     *
     * @return array<int, array<string, mixed>>
     */
    public function brandShelvesIn(string|int $categoryOrSlug): array
    {
        $key = 'category:brand-shelves:'.$categoryOrSlug;

        return Cache::remember($key, now()->addHour(), function () use ($categoryOrSlug) {
            $ids = $this->getDescendantIds($categoryOrSlug);

            if ($ids === []) {
                return [];
            }

            $stocked = $this->categoryIdsWithProducts();

            /*
             * Ordered by the parent's place in the menu, which is what decides
             * the dedupe below, and it matters more than it looks. Under
             * Laptop, ASUS stands on All Laptop, Gaming Laptop, Premium
             * Ultrabook and Laptop Bag; ordering these any other way gave the
             * row an ASUS that led to laptop bags. The shop already says which
             * of those shelves comes first, and that is the answer.
             */
            $shelves = Category::query()
                ->from('categories as c')
                ->join('categories as p', 'p.id', '=', 'c.parent_id')
                ->whereIn('c.id', $ids)
                ->whereNotNull('c.brand_id')
                ->where('c.is_active', true)
                ->whereIn('c.id', $stocked)
                ->with('brand:id,name,logo_path')
                ->orderBy('p.position')
                ->orderBy('p.name')
                ->orderBy('c.position')
                ->orderBy('c.name')
                ->get(['c.id', 'c.name', 'c.slug', 'c.brand_id', 'c.parent_id']);

            $seen = [];

            foreach ($shelves as $shelf) {
                if (! $shelf->brand || isset($seen[$shelf->brand_id])) {
                    continue;
                }

                $seen[$shelf->brand_id] = [
                    'id' => $shelf->id,
                    'name' => $shelf->brand->name,
                    'slug' => $shelf->slug,
                    'logo' => $shelf->brand->logo_path,
                ];
            }

            return array_values($seen);
        });
    }

    /**
     * Get Featured Categories for Homepage Bubble Carousel directly from DB.
     *
     * Previously this resolved descendants and counted products once per category —
     * roughly four queries each. Now the whole tree is read once and the counts come
     * back in a single grouped query, so it is a fixed 2 queries regardless of size.
     */
    public function getFeaturedCategories(): array
    {
        return Cache::remember(
            self::FEATURED_KEY,
            self::TTL,
            fn () => $this->buildFeaturedCategories()
        );
    }

    private function buildFeaturedCategories(): array
    {
        $categories = Category::where('is_active', true)
            ->inMenuOrder()
            ->where(function ($q) {
                $q->whereNull('parent_id')
                    ->orWhereIn('slug', ['cpu', 'graphics-card', 'motherboard', 'ram', 'storage', 'monitors', 'gaming-laptops', 'gaming-pc', 'desktops']);
            })
            // Ten of them, so which ten is the shop's decision rather than
            // the engine's — it had no order to take them in.
            ->take(10)
            ->get();

        // One read of the full category table, reused for every descendant lookup.
        $tree = $this->loadTree();

        $descendantMap = [];
        $allIds = [];
        foreach ($categories as $cat) {
            $ids = $this->descendantIdsFromTree($cat->id, $tree);
            $descendantMap[$cat->id] = $ids;
            $allIds = array_merge($allIds, $ids);
        }

        // One grouped count covering every category at once.
        $counts = empty($allIds)
            ? collect()
            : Product::active()
                ->join('category_product', 'category_product.product_id', '=', 'products.id')
                ->whereIn('category_product.category_id', array_unique($allIds))
                ->groupBy('category_product.category_id')
                ->selectRaw('category_product.category_id as category_id, COUNT(DISTINCT products.id) as aggregate')
                ->pluck('aggregate', 'category_id');

        $colors = [
            'desktops' => '#EA484F',
            'gaming-pc' => '#EA484F',
            'laptops' => '#2563EB',
            'gaming-laptops' => '#2563EB',
            'graphics-card' => '#10B981',
            'cpu' => '#7C3AED',
            'motherboard' => '#F59E0B',
            'monitors' => '#06B6D4',
            'ram' => '#EC4899',
            'storage' => '#8B5CF6',
            'accessories' => '#F97316',
            'gaming-gear' => '#14B8A6',
            'components' => '#D12127',
        ];

        return $categories->map(function (Category $cat) use ($colors, $descendantMap, $counts) {
            $count = 0;
            foreach ($descendantMap[$cat->id] ?? [] as $id) {
                $count += (int) ($counts[$id] ?? 0);
            }

            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'icon' => $cat->icon ?: 'Box',
                'color' => $colors[$cat->slug] ?? '#D12127',
                'count' => $count > 0 ? "{$count}+ Models" : 'Available',
            ];
        })->toArray();
    }

    /**
     * Get all descendant IDs for a category slug or ID.
     */
    public function getDescendantIds(string|int $categoryOrSlug): array
    {
        return Category::getDescendantIds($categoryOrSlug);
    }

    /**
     * id => [id, parent_id] for the whole table, plus a parent => children index.
     *
     * @return array{byParent: array<int, array<int, int>>}
     */
    private function loadTree(): array
    {
        $byParent = [];

        foreach (Category::query()->get(['id', 'parent_id']) as $row) {
            $byParent[(int) $row->parent_id][] = (int) $row->id;
        }

        return ['byParent' => $byParent];
    }

    /**
     * Walk the pre-loaded tree instead of hitting the database per level.
     *
     * @return array<int, int>
     */
    private function descendantIdsFromTree(int $categoryId, array $tree): array
    {
        $ids = [$categoryId];
        $queue = [$categoryId];

        while ($queue) {
            $current = array_shift($queue);

            foreach ($tree['byParent'][$current] ?? [] as $childId) {
                if (! in_array($childId, $ids, true)) {
                    $ids[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return $ids;
    }
}
