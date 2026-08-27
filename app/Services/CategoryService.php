<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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

        return Category::whereNull('parent_id')
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('is_offer', true)
                ->orWhereIn('id', $stocked))
            ->with(['children' => function ($query) use ($stocked) {
                $query->where('is_active', true)
                    ->whereIn('id', $stocked)
                    ->with(['children' => function ($q) use ($stocked) {
                        $q->where('is_active', true)
                            ->whereIn('id', $stocked);
                    }]);
            }])
            ->get()
            ->map(function (Category $cat) {
                return [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                    'badge' => $cat->badge,
                    'icon' => $cat->icon,
                    'isOffer' => (bool) $cat->is_offer,
                    'subcategories' => $cat->children->map(function ($sub) {
                        return [
                            'id' => $sub->id,
                            'name' => $sub->name,
                            'slug' => $sub->slug,
                            'icon' => $sub->icon,
                            'children' => $sub->children->map(function ($child) {
                                return [
                                    'id' => $child->id,
                                    'name' => $child->name,
                                    'slug' => $child->slug,
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
        $direct = Product::where('is_active', true)
            ->distinct()
            ->pluck('category_id')
            ->filter()
            ->all();

        if ($direct === []) {
            return [];
        }

        $parents = Category::pluck('parent_id', 'id');
        $stocked = array_flip($direct);

        foreach ($direct as $id) {
            $cursor = $parents[$id] ?? null;

            // Walk up to the root. The guard is against a cycle in the data,
            // which would otherwise hang the request.
            for ($depth = 0; $cursor !== null && $depth < 10; $depth++) {
                $stocked[$cursor] = true;
                $cursor = $parents[$cursor] ?? null;
            }
        }

        return array_keys($stocked);
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
            ->where(function ($q) {
                $q->whereNull('parent_id')
                    ->orWhereIn('slug', ['cpu', 'graphics-card', 'motherboard', 'ram', 'storage', 'monitors', 'gaming-laptops', 'gaming-pc', 'desktops']);
            })
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
                ->whereIn('category_id', array_unique($allIds))
                ->groupBy('category_id')
                ->selectRaw('category_id, COUNT(*) as aggregate')
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
