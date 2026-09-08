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

    /**
     * What actually needs doing, at most a few lines.
     *
     * The screen led with a three-column guide, a banner and a table of
     * thirteen rows by six columns, which is a lot to read to find out that
     * nothing is wrong. Most days nothing is, and the answer should take a
     * second to reach — so the detail moved behind a fold and this comes
     * first.
     *
     * Summarised rather than enumerated on purpose. "Five required slots have
     * nothing in stock" is one line worth reading; the same fact as five rows
     * is a list nobody finishes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function problems(): array
    {
        $slots = collect($this->slots());
        $problems = [];

        $starved = $slots->where('starved', true);

        if ($starved->isNotEmpty()) {
            $problems[] = [
                'tone' => 'danger',
                'title' => 'A build cannot be completed at all',
                'detail' => 'There is nothing to choose from in '
                    .$starved->pluck('label')->join(', ', ' and ')
                    .', which every build must have. Nobody can reach the end of the builder '
                    .'until a product is filed there.',
                'url' => '/admin/products',
            ];
        }

        $gaps = (int) $slots->sum('missing_specs');

        if ($gaps > 0) {
            /*
             * Named in the customer's terms, not the code's.
             *
             * This read "101 parts cannot be compatibility-checked", which says
             * what the software failed to do rather than what a shopper ends up
             * seeing — and "compatibility-checked" is not a phrase anybody
             * outside this codebase uses. It says what breaks, and which
             * shelves to start on.
             */
            $worst = $slots->where('missing_specs', '>', 0)
                ->sortByDesc('missing_specs')
                ->take(3)
                ->pluck('label');

            $problems[] = [
                'tone' => 'warn',
                'title' => $gaps.' '.($gaps === 1 ? 'product is' : 'products are')
                    .' missing details the builder needs',
                'detail' => 'Without them the builder cannot tell a customer whether their chosen '
                    .'parts fit together — it says it could not confirm the build, instead of passing '
                    .'or failing it. Mostly '.$worst->join(', ', ' and ')
                    .'. Open a product and fill in its Specifications.',
                // Straight to the products that need it, not the catalogue.
                'url' => '/admin/products?needs_specs=1',
            ];
        }

        // Only the required ones: a shopper can finish a build without a
        // second monitor, and thirteen rows of this would be noise.
        $dry = $slots->where('required', true)->where('parts', '>', 0)->where('in_stock', 0);

        if ($dry->isNotEmpty()) {
            /*
             * "5 required slots have nothing in stock" said it in the builder's
             * own vocabulary — a slot is an idea from the code, not something
             * an admin has ever been shown. What matters is that a customer
             * cannot buy the machine they just designed, so that is the line.
             */
            $problems[] = [
                'tone' => 'warn',
                'title' => 'Customers cannot buy a PC at the moment',
                'detail' => 'Every product is out of stock in '
                    .($dry->count() === 1
                        ? 'one part a build must have: '
                        : $dry->count().' of the parts a build must have: ')
                    .$dry->pluck('label')->join(', ', ' and ')
                    .'. A customer can still design a build and see the price, but not order it.',
                'url' => '/admin/stock',
            ];
        }

        return $problems;
    }

    /**
     * The products the builder cannot check, by id.
     *
     * The warning counted them and the Fix button landed on the whole
     * catalogue — 1,269 rows, with the ones that need work marked by a badge
     * somebody had to spot while paging. Counting a problem and then not being
     * able to reach it is barely better than not counting it.
     *
     * Resolved in PHP rather than SQL because "missing" means the product has
     * no specification under any of the names a check accepts, and those
     * aliases live in the engine. Only products on builder shelves are loaded,
     * which is a fraction of the catalogue.
     *
     * @return array<int, int>
     */
    public function productIdsMissingSpecs(): array
    {
        return collect($this->products->getPcBuilderCategories())
            ->flatMap(function (array $slot) {
                $slotId = (string) ($slot['id'] ?? '');
                $categoryIds = $this->categories->getDescendantIds($slotId);

                if (empty($categoryIds)) {
                    return [];
                }

                // The slot is passed rather than left to be inferred from the
                // product. These parts are gathered through the pivot, so one
                // can reach this slot on a category that is not its primary —
                // and inferring walks up from the primary, which would ask a
                // part filed under Casing for a processor's specs, or find no
                // slot at all and call it complete.
                $slotKey = PcCompatibilityService::slotFor($slotId);

                return Product::with('specifications', 'category.parent.parent')
                    ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn (Product $p) => ! empty($this->compatibility->missingSpecsFor($p, $slotKey)))
                    ->pluck('id');
            })
            ->unique()
            ->values()
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
                ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
                ->where('is_active', true)
                ->get();

        // Same as above: this slot is known, so it is stated rather than
        // guessed from a part that may only be listed here.
        $slotKey = PcCompatibilityService::slotFor($id);

        $missing = $parts->filter(
            fn (Product $p) => ! empty($this->compatibility->missingSpecsFor($p, $slotKey))
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
