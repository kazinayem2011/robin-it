<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The list of things a shop spends money on.
 *
 * Previously a constant in the Expense model, which meant changing it needed a
 * deploy. Every business spends on something the next one does not.
 */
class ExpenseCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/ExpenseCategories', [
            'categories' => ExpenseCategory::query()
                ->withCount('expenses')
                ->withSum('expenses as total_spend', 'amount')
                ->ordered()
                ->get(),
            // Said on the screen as well as in the code, because it is the
            // mistake this feature makes easy.
            'inventoryWords' => ExpenseCategory::INVENTORY_WORDS,
        ]);
    }

    public function store(ExpenseCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $category = ExpenseCategory::create($validated + [
            'slug' => ExpenseCategory::uniqueSlug($validated['name']),
            'sort_order' => $validated['sort_order'] ?? ((int) ExpenseCategory::max('sort_order') + 1),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->successResponse($category, "Category '{$category->name}' added.", 201);
    }

    /**
     * Rename or reorder a category.
     *
     * The slug is left alone: it is what the expenses already filed under this
     * category are joined by, and a rename should not become a different
     * category.
     */
    public function update(ExpenseCategoryRequest $request, int $id): JsonResponse
    {
        $category = ExpenseCategory::findOrFail($id);

        $category->update($request->validated());

        return $this->successResponse($category, 'Category updated.');
    }

    /**
     * Retire a category.
     *
     * Deactivated rather than deleted once anything has been filed under it,
     * the same way a supplier with deliveries is: the money was still spent,
     * and losing the record of it to tidy up a list would be the wrong trade.
     */
    public function destroy(int $id): JsonResponse
    {
        $category = ExpenseCategory::withCount('expenses')->findOrFail($id);

        if ($category->expenses_count > 0) {
            $category->update(['is_active' => false]);

            return $this->successResponse(
                $category,
                "'{$category->name}' has {$category->expenses_count} expense(s) filed under it, "
                    .'so it has been hidden rather than deleted.'
            );
        }

        $category->delete();

        return $this->successResponse([], 'Category removed.');
    }
}
