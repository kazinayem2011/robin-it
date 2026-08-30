<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductStoreRequest;
use App\Http\Requests\Admin\ProductUpdateRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\PcCompatibilityService;
use App\Services\ProductVariantService;
use App\Services\StockService;
use App\Support\RichText;
use App\Support\SearchTerm;
use App\Support\SlugFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(
        protected PcCompatibilityService $compatibility,
        protected ProductVariantService $variants,
        protected StockService $stock,
    ) {}

    /**
     * Products & inventory management view.
     */
    public function index(Request $request): Response
    {
        $search = $request->input('search', '');
        $categoryId = $request->input('category_id', '');

        // specifications and the category chain are eager-loaded because the
        // compatibility gap check below reads both. Loading them per product
        // instead turned this page into 61 queries for 20 rows.
        $query = Product::with([
            'category.parent.parent', 'categories:id', 'brand', 'images', 'variants', 'specifications',
        ])
            // withExists rather than asking per product: checking each row
            // individually took this page from 23 queries to 52.
            ->withExists(['stockMovements', 'orderItems'])
            ->latest();

        if (! empty($search)) {
            $term = SearchTerm::contains($search);

            $query->where(function ($q) use ($term) {
                $q->where('name', 'LIKE', $term)
                    ->orWhere('slug', 'LIKE', $term);
            });
        }

        if (! empty($categoryId)) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->paginate(20)->withQueryString();

        // Which builder components cannot be compatibility-checked because a
        // spec the engine reads is missing. It treats an absent spec as
        // "unknown" rather than a failure, which is right at check time and
        // invisible at catalogue time — the shop cannot tell "these fit" from
        // "we never said".
        $products->getCollection()->each(function (Product $product) {
            $product->setAttribute('missing_specs', $this->compatibility->missingSpecsFor($product));

            // Whether single/variant can still be changed. Once a product has
            // stock or appears on an order its shape is fixed, so the form
            // should say so rather than letting someone try and be refused.
            $product->setAttribute(
                'structure_locked',
                (bool) $product->stock_movements_exists || (bool) $product->order_items_exists
            );
        });

        return Inertia::render('Admin/Products', [
            'products' => $products,
            'categories' => Category::all(['id', 'name', 'slug', 'parent_id']),
            'brands' => Brand::all(['id', 'name', 'slug']),
            'filters' => [
                'search' => $search,
                'category_id' => $categoryId,
            ],
        ]);
    }

    /**
     * Create a new product.
     */
    public function store(ProductStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['slug'] = SlugFactory::unique(Product::class, $validated['name']);
        $validated['is_active'] = true;
        $validated['description'] = RichText::clean($validated['description'] ?? null);
        $validated['key_features'] = RichText::clean($validated['key_features'] ?? null);

        $opening = (int) ($validated['stock_quantity'] ?? 0);

        $product = Product::create($validated);

        // The one moment an absolute quantity is legitimate: stock already on the
        // shelf when the product is first entered. It is written to the ledger as
        // an opening balance, and from here on only purchases, sales, returns and
        // audited adjustments can move it.
        if ($opening > 0) {
            $this->stock->recordOpeningBalance($product, null, $opening, $request->user()?->id);
        }

        if (! empty($validated['image_path'])) {
            $product->images()->create([
                'image_path' => $validated['image_path'],
                'is_primary' => true,
            ]);
        }

        $this->syncSpecifications($product, $validated['specifications'] ?? null);
        $product->syncCategories($validated['category_ids'] ?? []);
        $this->syncRelated($product, $validated['related_product_ids'] ?? null);

        return $this->successResponse($product, "New product '{$product->name}' created successfully.", 201);
    }

    /**
     * Quick-update a product (price, discount, flags, options).
     */
    public function update(ProductUpdateRequest $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $attributes = $request->productAttributes();

        if (array_key_exists('description', $attributes)) {
            $attributes['description'] = RichText::clean($attributes['description']);
        }

        if (array_key_exists('key_features', $attributes)) {
            $attributes['key_features'] = RichText::clean($attributes['key_features']);
        }

        $product->update($attributes);

        $this->applyVariantChanges($request, $product);

        // validated(), not $attributes: productAttributes() strips this out, and
        // an empty array here is the instruction to clear the spec sheet, which
        // a null-filtering pick would silently discard.
        if ($request->has('specifications')) {
            $this->syncSpecifications($product, $request->validated()['specifications'] ?? []);
        }

        // Absent means "not editing these"; an empty array means "only the
        // primary" — which syncCategories keeps regardless.
        if ($request->has('category_ids')) {
            $product->syncCategories($request->validated()['category_ids'] ?? []);
        }

        if ($request->has('related_product_ids')) {
            $this->syncRelated($product, $request->validated()['related_product_ids'] ?? []);
        }

        if (! empty($attributes['image_path'])) {
            $primaryImage = $product->images()->where('is_primary', true)->first();

            if ($primaryImage) {
                $primaryImage->update(['image_path' => $attributes['image_path']]);
            } else {
                $product->images()->create([
                    'image_path' => $attributes['image_path'],
                    'is_primary' => true,
                ]);
            }
        }

        return $this->successResponse($product, "Product '{$product->name}' updated successfully.");
    }

    /**
     * Replace the hand-picked suggestions.
     *
     * A product cannot suggest itself — the sidebar would send a shopper to the
     * page they are already on — and order is the order they were chosen in.
     *
     * @param  array<int, int>|null  $ids
     */
    private function syncRelated(Product $product, ?array $ids): void
    {
        if ($ids === null) {
            return;
        }

        $payload = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $product->id)
            ->unique()
            ->values()
            ->mapWithKeys(fn (int $id, int $i) => [$id => ['position' => $i]])
            ->all();

        $product->relatedProducts()->sync($payload);
    }

    /**
     * Replace a product's spec sheet with what the form submitted.
     *
     * Replace rather than merge: the form always sends the whole sheet, so a
     * row the admin deleted is one that is simply absent from the payload.
     * Trying to diff row-by-row would need stable ids for rows that are still
     * being typed.
     *
     * Blank rows are dropped rather than rejected. The editor starts every
     * product with an empty row and leaves one at the bottom; making that an
     * error would mean nobody could save a product without first tidying up
     * after the UI.
     *
     * @param  array<int, array{group?: ?string, name?: ?string, value?: ?string}>|null  $rows
     */
    private function syncSpecifications(Product $product, ?array $rows): void
    {
        if ($rows === null) {
            return;
        }

        $product->specifications()->delete();

        $position = 0;

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));

            // A name with no value is half-typed, not a spec.
            if ($name === '' || $value === '') {
                continue;
            }

            $product->specifications()->create([
                'group' => trim((string) ($row['group'] ?? '')) ?: null,
                'name' => $name,
                'value' => $value,
                'sort_order' => $position++,
            ]);
        }
    }

    /**
     * Apply an option change requested by the product form.
     *
     * Three distinct moves, and only the conversions touch stock — and then only
     * to carry the same units across, never to change the total:
     *   single  -> options   the existing shelf is split across the new options
     *   options -> single    every option's stock is drained back to the product
     *   options -> options   labels and prices change, stock stays where it is
     */
    private function applyVariantChanges(ProductUpdateRequest $request, Product $product): void
    {
        if (! $request->has('has_variants') && ! $request->has('variants')) {
            return;
        }

        $validated = $request->validated();

        $wantsVariants = (bool) ($validated['has_variants'] ?? $product->has_variants);
        $definitions = $validated['variants'] ?? [];
        $attributes = $validated['variant_attributes'] ?? ($product->variant_attributes ?? []);
        $userId = $request->user()?->id;

        if ($wantsVariants && ! $product->has_variants) {
            $this->variants->convertToVariants($product, $attributes, $definitions, $userId);

            return;
        }

        if (! $wantsVariants && $product->has_variants) {
            $this->variants->convertToSingle($product, $userId);

            return;
        }

        if ($wantsVariants && $definitions !== []) {
            $this->variants->syncVariants($product, $attributes, $definitions);
        }
    }
}
