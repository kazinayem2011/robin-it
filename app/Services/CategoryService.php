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

    /** See version(). */
    public const VERSION_KEY = 'catalog.version';

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
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

    /**
     * Get the nested category tree for the Mega Menu.
     *
     * Every active category, whether or not anything is on it yet. Empty ones
     * used to be left out so nobody landed on "No products found"; with the
     * seeded samples gone that left a menu of three entries while the real
     * catalogue was still being entered, so the shop asked for the whole
     * structure to show, as StarTech's does.
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
            ->with(['children' => function ($query) {
                $query->where('is_active', true)
                    ->with(['children' => function ($q) {
                        $q->where('is_active', true)
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
     * The shelves one level down, for the row of pills across a category page.
     *
     * What Star Tech puts under the heading: on Office Equipment, Projector,
     * Conference System, PA System and the rest; on Projector, its own shelves
     * (Epson Projector, Projection Screen…); on a shelf with none below it,
     * nothing. It used to be the makers stocked anywhere beneath, which on a
     * department like Office Equipment was a row of brands where the way into
     * the department's own shelves belonged.
     *
     * Only shelves with something on them, by the mega menu's rule, so a pill
     * never opens onto "No products found". In the shop's menu order.
     *
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    public function subcategoriesOf(string|int $categoryOrSlug): array
    {
        $key = 'category:children:'.self::version().':'.$categoryOrSlug;

        return Cache::remember($key, self::TTL, function () use ($categoryOrSlug) {
            $parent = Category::query()
                ->where(is_int($categoryOrSlug) ? 'id' : 'slug', $categoryOrSlug)
                ->where('is_active', true)
                ->first(['id']);

            if (! $parent) {
                return [];
            }

            // Every active child, stocked or not, as the menu shows them.
            return Category::where('parent_id', $parent->id)
                ->where('is_active', true)
                ->inMenuOrder()
                ->get(['id', 'name', 'slug'])
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])
                ->all();
        });
    }

    /**
     * Bumped by flush(), so every per-category entry is left behind at once.
     *
     * The mega menu and featured list have one key each and are forgotten by
     * name; a key per category cannot be, so it carries this in its name
     * instead. An admin who adds a shelf sees it in the row straight away,
     * where the brand row it replaced waited out an hour.
     */
    private static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
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
