<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryService;
use App\Support\SearchTerm;
use App\Support\SlugFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        /*
         * In the shop's own order, which is what the admin is arranging. It
         * was `orderBy('id')` — the order they happened to be created in —
         * so the tree an admin reordered still showed itself unchanged.
         */
        $categories = Category::whereNull('parent_id')
            ->with([
                'children.children',
                'products',
                // Every level, because a brand shelf is usually the third one.
                'brand:id,name',
                'children.brand:id,name',
                'children.children.brand:id,name',
            ])
            ->inMenuOrder()
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
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->parent ? "{$c->parent->name} > {$c->name}" : $c->name,
                'level' => $c->parent_id ? ($c->parent->parent_id ? 3 : 2) : 1,
            ]);

        /*
         * A shelf can stand for a brand — "ASUS" under Brand PC is a shelf
         * with its own page, the way the trade lists them. The pair used to be
         * matched on their names at render time, which left 44 of the 144
         * brand shelves without a logo and came apart on any rename.
         */
        $brandOptions = Brand::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Admin/Categories', [
            'categories' => $categories,
            'parentOptions' => $parentOptions,
            'brandOptions' => $brandOptions,
        ]);
    }

    /**
     * Store a newly created category (root, L2 subcategory, or L3 series).
     */
    public function store(CategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $category = Category::create([
            'name' => $validated['name'],
            'slug' => SlugFactory::unique(Category::class, $validated['slug'] ?? $validated['name']),
            'parent_id' => $validated['parent_id'] ?? null,
            'brand_id' => $validated['brand_id'] ?? null,
            'icon' => $validated['icon'] ?? ($validated['parent_id'] ?? null ? null : 'Layers'),
            'badge' => $validated['badge'] ?? null,
            'is_offer' => $validated['is_offer'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->successResponse($category, "Category '{$category->name}' created successfully.", 201);
    }

    public function update(CategoryRequest $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $validated = $request->validated();

        $category->update([
            'name' => $validated['name'],
            'slug' => SlugFactory::unique(
                Category::class,
                $validated['slug'] ?? $validated['name'],
                $category->id
            ),
            'parent_id' => $validated['parent_id'] ?? null,
            'brand_id' => $validated['brand_id'] ?? null,
            'icon' => $validated['icon'] ?? $category->icon,
            'badge' => $validated['badge'] ?? null,
            'is_offer' => $validated['is_offer'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->successResponse($category, "Category '{$category->name}' updated successfully.");
    }

    /**
     * Move a category among its own siblings.
     *
     * Two ways of saying where, because there are two ways of asking. An arrow
     * sends a `direction` and means one step; a dragged card sends the
     * `position` it was dropped at and means put it there. Both land in the
     * same renumbering below, so the two routes cannot drift apart.
     *
     * The client sends where it wants the row, never the positions to write.
     * Otherwise it would have to know both rows' numbers and send two updates,
     * and two admins doing that at once leave the pair holding the same
     * number — precisely the tie the ordering has to break with a name.
     *
     * Scoped to siblings: `position` is per parent, so a subcategory moving up
     * moves within its own shelf and never past its parent into another one.
     */
    public function move(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'direction' => 'required_without:position|nullable|in:up,down',
            'position' => 'required_without:direction|nullable|integer|min:0',
        ]);

        $category = Category::findOrFail($id);
        $direction = $validated['direction'] ?? null;
        $target = isset($validated['position']) ? (int) $validated['position'] : null;

        return DB::transaction(function () use ($category, $direction, $target) {
            $siblings = Category::where('parent_id', $category->parent_id)
                ->lockForUpdate()
                ->inMenuOrder()
                ->get();

            $at = $siblings->search(fn (Category $c) => $c->id === $category->id);
            $last = $siblings->count() - 1;

            if ($at === false) {
                return $this->successResponse(
                    ['position' => $category->position],
                    "'{$category->name}' is already as far as it goes."
                );
            }

            /*
             * A dropped card is clamped, an arrow is not.
             *
             * They mean different things when they overshoot. Dropping below
             * the last row means "put it last", and the shelf the admin is
             * looking at may be shorter than the one on the server. An arrow
             * at the end means the row is already there — answered rather than
             * refused, because the buttons are disabled at the ends, so
             * arriving here means two people moved the same shelf at once and
             * the second one has simply lost a race.
             */
            if ($target !== null) {
                $to = max(0, min($target, $last));
            } else {
                $to = $direction === 'up' ? $at - 1 : $at + 1;

                if ($to < 0 || $to > $last) {
                    return $this->successResponse(
                        ['position' => $category->position],
                        "'{$category->name}' is already as far as it goes."
                    );
                }
            }

            if ($to === $at) {
                return $this->successResponse(
                    ['position' => $at],
                    "'{$category->name}' is already there."
                );
            }

            /*
             * Renumbered from zero across the whole set rather than swapping
             * two values. Rows that have never been moved all sit at 0, so a
             * swap between two of them changes nothing at all.
             */
            $ordered = $siblings->values();
            $moved = $ordered->splice($at, 1)->first();
            $ordered->splice($to, 0, [$moved]);

            foreach ($ordered as $index => $sibling) {
                if ($sibling->position !== $index) {
                    $sibling->forceFill(['position' => $index])->save();
                }
            }

            /*
             * The menu is cached for an hour and holds this order, so it has
             * to be dropped here. The model events that normally do it fire on
             * save — which the loop above may skip for rows already in place.
             */
            CategoryService::flush();

            return $this->successResponse(
                ['position' => $to],
                "'{$category->name}' moved."
            );
        });
    }

    /**
     * Delete a category, refusing when doing so would destroy catalogue data.
     *
     * products.category_id cascades on delete, so the previous implementation
     * silently wiped every product in the subtree, and orphaned grandchildren
     * were promoted to root categories. Now the admin is told what is in the way.
     */
    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $name = $category->name;

        $descendantIds = Category::getDescendantIds($category);

        /*
         * Primary category, deliberately — not the pivot, unlike everywhere
         * else that asks what is in a category.
         *
         * `products.category_id` is ON DELETE CASCADE, so these are the
         * products the delete would actually destroy. A product merely listed
         * here as an additional category loses the listing and nothing else,
         * because the pivot row cascades on its own. Counting those too would
         * refuse to delete a category over products that were never at risk,
         * and would overstate what the warning is warning about.
         */
        $productCount = Product::whereIn('category_id', $descendantIds)->count();

        if ($productCount > 0) {
            return $this->errorResponse(
                "'{$name}' still holds {$productCount} product(s), including its subcategories. "
                    .'Move or delete those products first — deleting the category would remove them permanently.',
                422,
                ApiCode::VALIDATION_ERROR,
                [
                    'product_count' => $productCount,
                    'category_ids' => array_values($descendantIds),
                ]
            );
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

        return $this->successResponse([], "Category '{$name}' deleted successfully.");
    }

    /**
     * Categories matching a search term, with their ancestry.
     *
     * Added so the product form stops receiving the entire tree. Admin/Products
     * shipped `Category::all()` as an Inertia prop — 1,392 rows and 113 KB of
     * JSON on every page load — to fill two dropdowns, and the resulting select
     * had 1,392 options and no way to search them.
     *
     * The path is returned with each row because the names repeat: "Type-C
     * Cable" is a real child of both Mobile Accessories and Cable, and a bare
     * name cannot tell a shopkeeper which one they are filing a product under.
     */
    /**
     * The questions this category asks about a product, inherited from above.
     *
     * The product form draws whatever comes back, so a shelf that gains a new
     * attribute gains the field with no code — and a category that declares
     * none simply has no section.
     */
    public function attributes(int $id): JsonResponse
    {
        $ids = [];

        for ($node = Category::find($id, ['id', 'parent_id']); $node; $node = $node->parent_id
            ? Category::find($node->parent_id, ['id', 'parent_id'])
            : null) {
            $ids[] = $node->id;
        }

        if ($ids === []) {
            return $this->successResponse([]);
        }

        $attributes = Attribute::query()
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $ids))
            ->with('values')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Attribute $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'slug' => $a->slug,
                'unit' => $a->unit,
                'input_type' => $a->input_type,
                'values' => $a->values->map(fn ($v) => [
                    'id' => $v->id,
                    'label' => $v->label,
                ])->all(),
            ])
            ->values()
            ->all();

        return $this->successResponse($attributes);
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        $query = Category::query()
            ->where('is_active', true)
            ->with('parent.parent:id,name')
            ->orderBy('name');

        if ($term !== '') {
            $query->where('name', 'like', SearchTerm::contains($term));
        }

        // A cap, not a page: this feeds a typeahead, and nobody scrolls to the
        // fortieth suggestion — they type another letter.
        $categories = $query->limit(40)->get(['id', 'name', 'slug', 'parent_id']);

        return $this->successResponse(
            $categories->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'path' => collect([
                    $category->parent?->parent?->name,
                    $category->parent?->name,
                ])->filter()->implode(' › '),
            ])->all()
        );
    }
}
