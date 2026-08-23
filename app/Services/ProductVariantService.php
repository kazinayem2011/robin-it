<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Switching a product between single and variant stock without inventing or
 * losing a single unit.
 *
 * Both directions move the existing on-hand across as `conversion` movements
 * that net to exactly zero, so the ledger still explains the balance afterwards
 * and the total the shop holds never changes at the moment of the switch.
 */
class ProductVariantService
{
    /** Orders that may still hand stock back, so the shelf must not move under them. */
    private const OPEN_ORDER_STATUSES = ['pending', 'processing', 'shipped'];

    public function __construct(
        protected StockService $stock
    ) {}

    /**
     * Turn a single-stock product into a variant product.
     *
     * @param  array<int, array>  $variants  each needs `options` and `opening_stock`
     *
     * @throws StorefrontException when the allocation does not match the shelf
     */
    public function convertToVariants(Product $product, array $attributes, array $variants, ?int $userId = null): Product
    {
        if ($product->has_variants) {
            throw new StorefrontException(
                'This product already uses options.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $this->assertNoOpenOrders($product, 'switch it to options');

        if ($variants === []) {
            throw new StorefrontException(
                'Add at least one option before switching this product to variants.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $onHand = (int) $product->stock_quantity;
        $allocated = array_sum(array_map(fn ($v) => (int) ($v['opening_stock'] ?? 0), $variants));

        // The whole point of the conversion is that the shop's stock does not
        // change, so the allocation has to account for every unit on the shelf.
        if ($allocated !== $onHand) {
            throw new StorefrontException(
                "This product has {$onHand} in stock but you have allocated {$allocated} across the options. "
                    .'The two must match so no stock is created or lost.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        return DB::transaction(function () use ($product, $attributes, $variants, $onHand, $userId) {
            // Take everything off the product-level shelf first.
            if ($onHand > 0) {
                $this->stock->record($product, null, -$onHand, StockMovement::CONVERSION, [
                    'note' => 'Moved to per-option stock',
                    'user_id' => $userId,
                ]);
            }

            $product->forceFill([
                'has_variants' => true,
                'variant_attributes' => array_values($attributes),
            ])->save();

            foreach (array_values($variants) as $position => $definition) {
                $created = $this->createVariant($product, $definition, $position);
                $opening = (int) ($definition['opening_stock'] ?? 0);

                if ($opening > 0) {
                    $this->stock->record($product, $created, $opening, StockMovement::CONVERSION, [
                        'note' => 'Moved from the product\'s single stock',
                        'user_id' => $userId,
                    ]);
                }
            }

            $this->stock->syncProductTotal($product);

            return $product->fresh('variants');
        });
    }

    /**
     * Collapse a variant product back to a single stock pool.
     *
     * Every variant's stock is drained into the product, so the total is
     * preserved exactly. Variants are deactivated rather than deleted because
     * past orders still reference them.
     */
    public function convertToSingle(Product $product, ?int $userId = null): Product
    {
        if (! $product->has_variants) {
            throw new StorefrontException(
                'This product already uses a single stock pool.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $this->assertNoOpenOrders($product, 'switch it back to a single stock pool');

        return DB::transaction(function () use ($product, $userId) {
            $variants = ProductVariant::where('product_id', $product->id)->lockForUpdate()->get();
            $total = 0;

            foreach ($variants as $variant) {
                $held = (int) $variant->stock_quantity;

                if ($held > 0) {
                    $this->stock->record($product, $variant, -$held, StockMovement::CONVERSION, [
                        'note' => 'Moved back to the product\'s single stock',
                        'user_id' => $userId,
                    ]);
                    $total += $held;
                }

                $variant->update(['is_active' => false]);
            }

            // Flip the flag before crediting the product, otherwise syncProductTotal
            // would immediately overwrite the balance with the (now zero) variant sum.
            $product->forceFill([
                'has_variants' => false,
                'variant_attributes' => null,
                'stock_quantity' => 0,
            ])->save();

            if ($total > 0) {
                $this->stock->record($product, null, $total, StockMovement::CONVERSION, [
                    'note' => 'Collected from per-option stock',
                    'user_id' => $userId,
                ]);
            }

            return $product->fresh('variants');
        });
    }

    /**
     * Add, edit and retire options on a product that already uses variants.
     *
     * Never touches stock: quantities only move through purchases, sales,
     * returns and audited adjustments.
     */
    public function syncVariants(Product $product, array $attributes, array $variants): Product
    {
        if (! $product->has_variants) {
            throw new StorefrontException(
                'Switch this product to options before editing them.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        return DB::transaction(function () use ($product, $attributes, $variants) {
            $product->forceFill(['variant_attributes' => array_values($attributes)])->save();

            $keptIds = [];

            foreach (array_values($variants) as $position => $definition) {
                $id = $definition['id'] ?? null;

                if ($id && $existing = ProductVariant::where('product_id', $product->id)->find($id)) {
                    $existing->update($this->variantAttributes($definition, $position));
                    $keptIds[] = $existing->id;

                    continue;
                }

                $keptIds[] = $this->createVariant($product, $definition, $position)->id;
            }

            $this->retireMissingVariants($product, $keptIds);
            $this->stock->syncProductTotal($product);

            return $product->fresh('variants');
        });
    }

    /**
     * Options the admin removed from the form.
     *
     * One holding stock is kept and deactivated rather than deleted — deleting it
     * would quietly destroy units that are physically on the shelf.
     */
    private function retireMissingVariants(Product $product, array $keptIds): void
    {
        $removed = ProductVariant::where('product_id', $product->id)
            ->whereNotIn('id', $keptIds ?: [0])
            ->get();

        foreach ($removed as $variant) {
            $hasHistory = StockMovement::where('product_variant_id', $variant->id)->exists()
                || OrderItem::where('product_variant_id', $variant->id)->exists();

            if ((int) $variant->stock_quantity !== 0 || $hasHistory) {
                $variant->update(['is_active' => false]);

                continue;
            }

            $variant->delete();
        }
    }

    private function createVariant(Product $product, array $definition, int $position): ProductVariant
    {
        return ProductVariant::create(array_merge(
            $this->variantAttributes($definition, $position),
            ['product_id' => $product->id, 'stock_quantity' => 0]
        ));
    }

    /**
     * Note the deliberate absence of stock_quantity — variant edits never move stock.
     */
    private function variantAttributes(array $definition, int $position): array
    {
        $options = $definition['options'] ?? [];

        return [
            'name' => trim((string) ($definition['name'] ?? '')) ?: ProductVariant::labelFor($options),
            'sku' => blank($definition['sku'] ?? null) ? null : trim((string) $definition['sku']),
            'options' => $options,
            'price' => $this->money($definition['price'] ?? null),
            'discount_price' => $this->money($definition['discount_price'] ?? null),
            'image_url' => blank($definition['image_url'] ?? null) ? null : $definition['image_url'],
            // Not stock — just the level at which to buy more of this option.
            'reorder_level' => blank($definition['reorder_level'] ?? null)
                ? null
                : (int) $definition['reorder_level'],
            'is_active' => (bool) ($definition['is_active'] ?? true),
            'position' => $position,
        ];
    }

    private function money($value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    /**
     * A pending or in-flight order still holds reserved units that would need to
     * go back to whichever shelf they came from. Moving the shelf underneath it
     * would make that impossible to do honestly, so the switch waits.
     */
    private function assertNoOpenOrders(Product $product, string $action): void
    {
        $open = OrderItem::where('product_id', $product->id)
            ->whereHas('order', fn ($q) => $q->whereIn('status', self::OPEN_ORDER_STATUSES))
            ->count();

        if ($open > 0) {
            $orders = Order::whereIn('status', self::OPEN_ORDER_STATUSES)
                ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
                ->count();

            throw new StorefrontException(
                "{$orders} open order(s) still contain this product. Complete or cancel them before you {$action}, "
                    .'so their stock goes back to the right place.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }
    }
}
