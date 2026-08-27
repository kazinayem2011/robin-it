<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiCode;
use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SavedPcBuild;
use App\Services\PcCompatibilityService;
use App\Services\ProductService;
use App\Support\BrandDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PcBuilderController extends Controller
{
    /** A full build is a handful of parts, not an unbounded blob. */
    private const MAX_COMPONENTS = 30;

    public function save(Request $request): JsonResponse
    {
        // The rules judge the number, not its punctuation.
        PhoneHelper::canonicalise($request, 'customer_phone');

        $validated = $request->validate([
            'components' => 'required|array|min:1|max:'.self::MAX_COMPONENTS,
            'components.*.componentId' => 'required|string|max:120',
            'components.*.product_id' => 'required|integer|exists:products,id',
            'components.*.quantity' => 'nullable|integer|min:1|max:10',
            'build_name' => 'nullable|string|max:150',
            'customer_name' => 'nullable|string|max:100',
            'customer_phone' => ['nullable', 'string', 'max:20', PhoneHelper::RULE],
        ], [
            'components.required' => 'Pick at least one component before saving your build.',
            'components.max' => 'A saved build can hold up to '.self::MAX_COMPONENTS.' components.',
            'components.*.product_id.exists' => 'One of the selected components is no longer available.',
            'customer_phone.regex' => 'Please enter a valid 11-digit Bangladeshi mobile number.',
        ]);

        // Price the build from the catalogue, not from whatever the browser posted.
        $totalPrice = $this->priceBuild($validated['components']);

        $build = SavedPcBuild::create([
            'share_code' => strtoupper(Str::random(8)),
            'user_id' => auth('sanctum')->id() ?? auth()->id(),
            'build_name' => $validated['build_name'] ?? BrandDetails::name().' Custom Build',
            'components' => $validated['components'],
            'total_price' => $totalPrice,
            'customer_name' => $validated['customer_name'] ?? null,
            'customer_phone' => $validated['customer_phone'] ?? null,
        ]);

        return $this->successResponse([
            'share_code' => $build->share_code,
            'share_url' => url("/pc-builder?share={$build->share_code}"),
            'build_name' => $build->build_name,
            'total_price' => (float) $build->total_price,
        ], 'PC Build configuration saved successfully!', 201);
    }

    /**
     * Resolve a posted selection of slot => product_id into loaded models,
     * ignoring anything that no longer exists in the catalogue.
     *
     * @param  array<string, mixed>  $selection
     * @return array<string, Product>
     */
    private function resolveSelection(array $selection): array
    {
        $ids = collect($selection)->filter()->map(fn ($v) => (int) $v)->all();

        if ($ids === []) {
            return [];
        }

        $products = Product::whereIn('id', array_values($ids))
            ->with('specifications')
            ->get()
            ->keyBy('id');

        $resolved = [];
        foreach ($ids as $slot => $id) {
            if ($product = $products->get($id)) {
                $resolved[$slot] = $product;
            }
        }

        return $resolved;
    }

    /**
     * Check a build for compatibility conflicts.
     *
     * The builder previously filtered on category alone while advertising an
     * "Instant Compatibility Matrix", so mismatched parts could be bought together.
     */
    public function check(Request $request, PcCompatibilityService $compatibility): JsonResponse
    {
        $validated = $request->validate([
            'selection' => 'required|array',
            'selection.*' => 'nullable|integer|exists:products,id',
        ], [
            'selection.required' => 'Choose at least one component to check.',
        ]);

        $report = $compatibility->analyse($this->resolveSelection($validated['selection']));

        return $this->successResponse($report, match ($report['status']) {
            PcCompatibilityService::FAIL => 'This build has compatibility conflicts.',
            PcCompatibilityService::PASS => 'All selected parts are compatible.',
            default => 'Some parts could not be verified.',
        });
    }

    public function load(string $shareCode, ProductService $productService): JsonResponse
    {
        $build = SavedPcBuild::where('share_code', strtoupper(trim($shareCode)))->first();

        if (! $build) {
            return $this->errorResponse(
                'That build link is no longer valid. Please ask for an up-to-date share link.',
                404,
                ApiCode::NOT_FOUND
            );
        }

        // Rehydrate against the live catalogue so a shared build always opens with
        // current prices and stock, not a snapshot from whenever it was saved.
        $components = $this->hydrateComponents($build->components ?? [], $productService);

        return $this->successResponse([
            'share_code' => $build->share_code,
            'build_name' => $build->build_name,
            'components' => $components,
            'total_price' => round(collect($components)->sum(
                fn ($c) => ($c['product']['raw_price'] ?? 0) * ($c['quantity'] ?? 1)
            ), 2),
            'unavailable_count' => collect($components)->whereNull('product')->count(),
            'created_at' => $build->created_at->format('d M Y, h:i A'),
        ], 'PC Build loaded successfully.');
    }

    /**
     * Attach live product data to each saved slot. A component that has since been
     * delisted comes back with a null product so the UI can flag the gap.
     */
    private function hydrateComponents(array $components, ProductService $productService): array
    {
        $ids = collect($components)->pluck('product_id')->filter()->unique();

        $products = Product::whereIn('id', $ids)
            ->with(['brand', 'images', 'specifications', 'category'])
            ->withCatalogAggregates()
            ->get()
            ->keyBy('id');

        return collect($components)->map(function ($component) use ($products, $productService) {
            $product = $products->get($component['product_id'] ?? null);

            return [
                'componentId' => $component['componentId'] ?? null,
                'product_id' => $component['product_id'] ?? null,
                'quantity' => max(1, (int) ($component['quantity'] ?? 1)),
                'product' => $product ? $productService->formatProductCardData($product) : null,
            ];
        })->values()->all();
    }

    /**
     * Sum the live catalogue price of every component in the build.
     */
    private function priceBuild(array $components): float
    {
        $ids = collect($components)->pluck('product_id')->filter()->unique();
        $products = Product::whereIn('id', $ids)->get()->keyBy('id');

        $total = 0.0;
        foreach ($components as $component) {
            $product = $products->get($component['product_id'] ?? null);

            if ($product) {
                $total += $product->effective_price * max(1, (int) ($component['quantity'] ?? 1));
            }
        }

        return round($total, 2);
    }
}
