<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Support\SearchTerm;
use App\Support\SlugFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Managing the brands the shop stocks.
 *
 * There was no screen for this at all: the twenty-eight brands existed
 * because a seeder made them, and nothing in the application could add a
 * twenty-ninth. `logo_path` was read in three places — including the mega
 * menu — and written by nothing, which is why every brand there falls back
 * to a lettermark.
 */
class BrandController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $brands = Brand::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', SearchTerm::contains($search)))
            ->withCount('products')
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Admin/Brands', [
            'brands' => $brands,
            'filters' => ['search' => $search],
            'counts' => [
                'total' => Brand::count(),
                'featured' => Brand::where('is_featured', true)->count(),
                'withLogo' => Brand::whereNotNull('logo_path')->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $brand = Brand::create([
            ...$validated,
            'slug' => SlugFactory::unique(Brand::class, $validated['name']),
        ]);

        return $this->successResponse($brand, "Brand '{$brand->name}' created.", 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $brand = Brand::findOrFail($id);

        $brand->update($request->validate($this->rules($id)));

        return $this->successResponse($brand, "Brand '{$brand->name}' updated.");
    }

    /**
     * Products keep their brand_id as null rather than being deleted with it —
     * losing a supplier is not losing the stock on the shelf.
     */
    public function destroy(int $id): JsonResponse
    {
        $brand = Brand::withCount('products')->findOrFail($id);
        $name = $brand->name;
        $orphaned = $brand->products_count;

        $brand->delete();

        return $this->successResponse(
            null,
            $orphaned > 0
                ? "Brand '{$name}' deleted. {$orphaned} product(s) now have no brand."
                : "Brand '{$name}' deleted."
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?int $ignoreId = null): array
    {
        return [
            // Unique because the mega menu matches a brand category to its logo
            // by name, and two rows called "ASUS" make that a coin toss.
            'name' => ['required', 'string', 'max:120', Rule::unique('brands', 'name')->ignore($ignoreId)],
            'logo_path' => 'nullable|string|max:2048',
            'is_featured' => 'nullable|boolean',
        ];
    }
}
