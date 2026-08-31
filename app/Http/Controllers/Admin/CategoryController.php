<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\Category;
use App\Models\Product;
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
        $categories = Category::whereNull('parent_id')
            ->with(['children.children', 'products'])
            ->orderBy('id', 'asc')
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

        return Inertia::render('Admin/Categories', [
            'categories' => $categories,
            'parentOptions' => $parentOptions,
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
            'icon' => $validated['icon'] ?? $category->icon,
            'badge' => $validated['badge'] ?? null,
            'is_offer' => $validated['is_offer'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->successResponse($category, "Category '{$category->name}' updated successfully.");
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
