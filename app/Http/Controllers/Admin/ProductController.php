<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductStoreRequest;
use App\Http\Requests\Admin\ProductUpdateRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\PcCompatibilityService;
use App\Services\ProductGalleryService;
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
        protected ProductGalleryService $gallery,
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
            'category.parent.parent', 'categories:id', 'brand', 'images', 'specifications',
            // variants.images so the edit form can show each option's own
            // photos without a second request per row.
            'variants', 'variants.images',
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
            /*
             * The tree is no longer sent. It was 1,392 rows and 113 KB of JSON
             * on every load of this screen, to fill two dropdowns — and the
             * dropdown it filled had 1,392 options and no search. Both now use
             * a typeahead against categories/search.
             */
            'brands' => Brand::all(['id', 'name', 'slug']),
            'filters' => [
                'search' => $search,
                'category_id' => $categoryId,
            ],
        ]);
    }

    /**
     * Everything known about one product, for the details panel.
     *
     * The index deliberately ships a thin row — it is twenty products a page
     * and the tree alone was 113 KB — so the columns can only show a name, a
     * price and a stock figure. Answering "what is this product, exactly?"
     * meant opening the edit form and reading it out of the inputs, which is
     * both slower and a live form somebody can save by accident.
     *
     * One product, everything on it, read-only.
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::with([
            'category.parent.parent',
            'categories:id,name,slug,parent_id',
            'categories.parent:id,name',
            'brand:id,name,slug,logo_path',
            'images',
            'variants',
            'variants.images',
            'specifications',
            'quantityDiscounts',
            'relatedProducts:id,name,slug,price',
            'stockLevels.store:id,name',
        ])
            ->withCount([
                'reviews',
                'questions',
                'orderItems',
                'stockMovements',
            ])
            ->findOrFail($id);

        // Computed here, not in the browser: whether a discount is currently
        // running, what a saving comes to, where the reorder level actually
        // falls. A second implementation in JSX is a second set of rounding
        // decisions, and it drifts from what the shop charges.
        //
        // effective_price, has_discount, in_stock, stock_status_label and
        // emi_monthly are already on $appends; these are the rest.
        $product->setAttribute('missing_specs', $this->compatibility->missingSpecsFor($product));
        $product->setAttribute('saving', $product->saving);
        $product->setAttribute('discount_window_open', $product->discountWindowIsOpen());
        $product->setAttribute('average_rating', $product->average_rating);
        $product->setAttribute('reorder_level_effective', $product->reorderLevel());
        $product->setAttribute('needs_reorder', $product->needsReorder());

        return $this->successResponse($product);
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

        /*
         * Options are applied after the row exists, through the same service
         * the edit form uses — never by mass assignment.
         *
         * has_variants is a fillable column, so leaving it in would create a
         * product already flagged as sold in options but with none, and
         * convertToVariants would then refuse it as "already uses options".
         */
        $wantsVariants = (bool) ($validated['has_variants'] ?? false);
        $variantDefinitions = $wantsVariants ? ($validated['variants'] ?? []) : [];
        $variantAttributes = $validated['variant_attributes'] ?? [];

        unset($validated['has_variants'], $validated['variant_attributes'], $validated['variants']);

        /*
         * A product sold in options has no shelf of its own — its stock is the
         * sum of the options'. So the opening quantity is read per option and
         * the product row starts at nothing.
         */
        $opening = $wantsVariants
            ? 0
            : (int) ($validated['stock_quantity'] ?? 0);

        if ($wantsVariants) {
            $validated['stock_quantity'] = 0;
        }

        $product = Product::create($validated);

        // The one moment an absolute quantity is legitimate: stock already on the
        // shelf when the product is first entered. It is written to the ledger as
        // an opening balance, and from here on only purchases, sales, returns and
        // audited adjustments can move it.
        if ($opening > 0) {
            $this->stock->recordOpeningBalance($product, null, $opening, $request->user()?->id);
        }

        /*
         * The options editor is on the create form, so a shopkeeper entering a
         * product sold in sizes fills it in and presses Create. Until now the
         * request came back 201 with a success toast and the options were
         * thrown away — the product saved as a single-stock item and had to be
         * opened again and given its options a second time.
         */
        if ($variantDefinitions !== []) {
            $this->createWithOptions(
                $product,
                $variantAttributes,
                $variantDefinitions,
                $request->user()?->id,
            );
        }

        $this->gallery->syncProduct($product, $this->galleryFrom($validated));

        $this->syncSpecifications($product, $validated['specifications'] ?? null);
        $product->syncCategories($validated['category_ids'] ?? []);
        $this->syncRelated($product, $validated['related_product_ids'] ?? null);
        $this->syncQuantityDiscounts($product, $validated['quantity_discounts'] ?? null);

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

        if ($request->has('quantity_discounts')) {
            $this->syncQuantityDiscounts($product, $request->validated()['quantity_discounts'] ?? []);
        }

        // Absent means "not editing the photos"; an empty array means "remove
        // them all". Saving a price must not clear a gallery.
        if ($request->has('images') || $request->has('image_path')) {
            $this->gallery->syncProduct($product, $this->galleryFrom($request->validated()));
        }

        return $this->successResponse($product, "Product '{$product->name}' updated successfully.");
    }

    /**
     * Turn a freshly created product into one sold in options.
     *
     * The switch happens while the row is still pristine, because the service
     * refuses to restructure a product that has stock recorded against it —
     * and an opening balance written first is exactly that. So the options are
     * made empty, and each one's opening quantity is posted afterwards as its
     * own balance, the same shape a single product's opening stock takes.
     *
     * @param  array<int, string>  $attributes
     * @param  array<int, array<string, mixed>>  $definitions
     */
    private function createWithOptions(Product $product, array $attributes, array $definitions, ?int $userId): void
    {
        $empty = array_map(
            fn (array $definition) => array_replace($definition, ['opening_stock' => 0]),
            array_values($definitions),
        );

        $this->variants->convertToVariants($product, $attributes, $empty, $userId);

        $variants = $product->refresh()->variants()->orderBy('position')->get();

        foreach (array_values($definitions) as $position => $definition) {
            $opening = (int) ($definition['opening_stock'] ?? 0);
            $variant = $variants[$position] ?? null;

            if ($opening > 0 && $variant) {
                $this->stock->record($product, $variant, $opening, StockMovement::OPENING, [
                    'note' => 'Stock already on the shelf when this option was entered',
                    'user_id' => $userId,
                ]);
            }
        }
    }

    /**
     * The gallery a request is asking for.
     *
     * `image_path` was the whole of a product's photography before galleries:
     * one string, one photo. It is still accepted, and still means "this is the
     * lead shot", so an older client — or anything posting the single field —
     * keeps working instead of silently saving a product with no picture.
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    private function galleryFrom(array $validated): array
    {
        $images = $validated['images'] ?? null;

        if (is_array($images)) {
            return $images;
        }

        $single = trim((string) ($validated['image_path'] ?? ''));

        return $single === '' ? [] : [['image_path' => $single, 'is_primary' => true]];
    }

    /**
     * Replace the buy-more-pay-less tiers.
     *
     * Keyed by quantity while building, because two tiers starting at the same
     * number is not a cheaper deal but an ambiguity — and the table's unique
     * index would reject the pair with a 500 rather than a message.
     *
     * @param  array<int, array{min_quantity?: int, price?: float}>|null  $tiers
     */
    private function syncQuantityDiscounts(Product $product, ?array $tiers): void
    {
        if ($tiers === null) {
            return;
        }

        $product->quantityDiscounts()->delete();

        collect($tiers)
            ->filter(fn ($tier) => ! empty($tier['min_quantity']) && isset($tier['price']))
            ->keyBy(fn ($tier) => (int) $tier['min_quantity'])
            ->each(fn ($tier, $quantity) => $product->quantityDiscounts()->create([
                'min_quantity' => $quantity,
                'price' => (float) $tier['price'],
            ]));
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
