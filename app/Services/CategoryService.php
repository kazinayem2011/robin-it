<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;

class CategoryService
{
    /**
     * Get the nested category tree for the Mega Menu.
     */
    public function getMegaMenuTree(): Collection
    {
        return Category::whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => function ($query) {
                $query->where('is_active', true)
                    ->with(['children' => function ($q) {
                        $q->where('is_active', true);
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
                            }),
                        ];
                    }),
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
