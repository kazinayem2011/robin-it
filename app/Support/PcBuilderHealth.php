<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryService;
use App\Services\PcCompatibilityService;
use App\Services\ProductService;

/**
 * What the PC Builder is actually offering, slot by slot.
 *
 * None of the rules that govern the builder are visible anywhere an admin
 * looks. A part appears in a slot because of the category it is filed under
 * and its Active tick — not stock, not a flag on the builder, and there is no
 * screen that says so. Compatibility checking is driven by specifications
 * whose names have to match, and a missing one is treated as "unknown" rather
 * than a failure, so an unchecked build looks exactly like a checked one.
 *
 * The failures are all silent, which is the point of gathering this:
 *
 *  - a required slot with nothing in it still appears, so a build looks
 *    completable when it cannot be completed;
 *  - an optional slot with nothing in it, or any slot whose category is gone,
 *    is dropped from the builder without a word;
 *  - parts that are out of stock are still offered, because the filter is the
 *    Active flag alone;
 *  - past sixty products a slot silently stops showing the oldest.
 *
 * Read-only. It answers "why is that not in the builder?" without anybody
 * having to read the service that decides it.
 */
class PcBuilderHealth
{
    /** Matches the cap in ProductService::getPcBuilderComponents(). */
    private const SHOWN_PER_SLOT = ProductService::MAX_PER_PAGE;

    public function __construct(
        private readonly ProductService $products,
        private readonly CategoryService $categories,
        private readonly PcCompatibilityService $compatibility,
    ) {}

    /**
     * One row per slot the builder offers, plus why each is the way it is.
     *
     * @return array<int, array<string, mixed>>
     */
    public function slots(): array
    {
        return collect($this->products->getPcBuilderCategories())
            ->map(fn (array $slot) => $this->describe($slot))
            ->all();
    }

    /** The counts the dashboard card needs, without the per-slot detail. */
    public function summary(): array
    {
        $slots = $this->slots();

        return [
            'slots' => count($slots),
            'starved' => collect($slots)->where('starved', true)->count(),
            'spec_gaps' => collect($slots)->sum('missing_specs'),
        ];
    }

    private function describe(array $slot): array
    {
        $id = (string) ($slot['id'] ?? '');
        $categoryIds = $this->categories->getDescendantIds($id);

        $parts = empty($categoryIds)
            ? collect()
            : Product::with('specifications', 'category')
                ->whereIn('category_id', $categoryIds)
                ->where('is_active', true)
                ->get();

        $missing = $parts->filter(
            fn (Product $p) => ! empty($this->compatibility->missingSpecsFor($p))
        );

        return [
            'id' => $id,
            'label' => $slot['name'] ?? $id,
            'required' => (bool) ($slot['required'] ?? false),
            'group' => $slot['group'] ?? 'other',

            /*
             * Where the parts come from, written the way the product form
             * writes it.
             *
             * This showed the slug — component-processor — which is a
             * developer's name for the shelf and appears nowhere an admin can
             * see. The category picker on the product form shows an ancestry,
             * "Component › Processor", so that is what this shows: the thing
             * they are about to go and click, spelled identically.
             *
             * The slug stays as the hover title, for whoever is reading this
             * to debug rather than to file a product.
             */
            'category' => $this->categoryPath($slot['category_slug'] ?? $id),
            'category_slug' => $slot['category_slug'] ?? $id,

            /*
             * A required slot with nothing in it is kept by the builder and
             * marked unavailable, on the grounds that hiding it would make a
             * build look completable when it is not. It is the one state here
             * that actually stops a customer finishing, so it is called out.
             */
            'starved' => (bool) ($slot['required'] ?? false) && $parts->isEmpty(),

            'parts' => $parts->count(),
            'in_stock' => $parts->where('stock_quantity', '>', 0)->count(),
            'out_of_stock' => $parts->where('stock_quantity', '<=', 0)->count(),
            'missing_specs' => $missing->count(),
            'needs_specs' => $this->specNamesFor($id),

            'over_cap' => $parts->count() > self::SHOWN_PER_SLOT,
            'shown' => min($parts->count(), self::SHOWN_PER_SLOT),
        ];
    }

    /**
     * A shelf written as the product form's category picker writes it, so an
     * admin can match one against the other without translating.
     */
    private function categoryPath(string $slug): string
    {
        $category = Category::where('slug', $slug)->first();

        if (! $category) {
            return $slug;
        }

        $names = [$category->name];
        $parent = $category->parent;
        $guard = 0;

        while ($parent && $guard++ < 6) {
            array_unshift($names, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' › ', $names);
    }

    /**
     * The specification names this slot's compatibility checks look for, first
     * spelling of each. Without them a part is carried as "unknown" and the
     * build is never actually checked.
     *
     * @return array<int, string>
     */
    private function specNamesFor(string $slotId): array
    {
        // The builder's own id, translated to the name the check table uses —
        // the very mismatch that made every one of these checks silent.
        $slot = PcCompatibilityService::slotFor($slotId);

        if (! $slot || ! isset(PcCompatibilityService::REQUIRED_SPECS[$slot])) {
            return [];
        }

        return array_map(
            fn (array $aliases) => $aliases[0],
            PcCompatibilityService::REQUIRED_SPECS[$slot]
        );
    }
}
