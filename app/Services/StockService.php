<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only thing in the application allowed to change stock.
 *
 * Stock used to be a plain integer that the admin product form wrote as an
 * absolute number, so a form opened before a sale would put the sold units back
 * on the shelf when saved. Every change now goes through here, leaves a row in
 * the ledger, and `stock_quantity` is nothing more than the cached balance.
 *
 * Reads of `stock_quantity` stay fast; writes are serialised per stock unit with
 * a row lock, so two concurrent orders can never both take the last unit.
 */
class StockService
{
    /** Reasons an admin may give for a manual adjustment. */
    public const ADJUSTMENT_REASONS = [
        'stock_take' => 'Stock-take correction',
        'damaged' => 'Damaged or broken',
        'lost' => 'Lost or stolen',
        'supplier_return' => 'Returned to supplier',
        'other' => 'Other (explain in the note)',
    ];

    /**
     * Write one ledger row and move the cached balance with it.
     *
     * Must run inside a transaction — callers that change several units at once
     * (a receipt, an order) wrap the whole batch so it commits or fails together.
     *
     * @param  int  $delta  signed: positive puts units on the shelf, negative takes them off
     *
     * @throws StorefrontException when the change would drive stock below zero
     */
    public function record(
        Product $product,
        ?ProductVariant $variant,
        int $delta,
        string $type,
        array $meta = []
    ): StockMovement {
        if (! in_array($type, StockMovement::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown stock movement type: {$type}");
        }

        if ($delta === 0) {
            throw new \InvalidArgumentException('A stock movement must change the balance.');
        }

        return DB::transaction(function () use ($product, $variant, $delta, $type, $meta) {
            // Lock the row that owns the balance so a concurrent sale cannot read
            // the same "before" value and write a conflicting "after".
            $current = $variant
                ? (int) ProductVariant::whereKey($variant->id)->lockForUpdate()->value('stock_quantity')
                : (int) Product::whereKey($product->id)->lockForUpdate()->value('stock_quantity');

            $balanceAfter = $current + $delta;

            if ($balanceAfter < 0) {
                throw StorefrontException::outOfStock(
                    $this->unitName($product, $variant),
                    max(0, $current)
                );
            }

            $reference = $meta['reference'] ?? null;

            $movement = StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => $delta,
                'type' => $type,
                'balance_after' => $balanceAfter,
                'reference_type' => $reference instanceof Model ? $reference->getMorphClass() : null,
                'reference_id' => $reference instanceof Model ? $reference->getKey() : null,
                'reason' => $meta['reason'] ?? null,
                'note' => $meta['note'] ?? null,
                'unit_cost' => $meta['unit_cost'] ?? null,
                // Customer-driven movements have no admin behind them.
                'user_id' => $meta['user_id'] ?? Auth::id(),
            ]);

            if ($variant) {
                ProductVariant::whereKey($variant->id)->update(['stock_quantity' => $balanceAfter]);
                $variant->stock_quantity = $balanceAfter;
                $this->syncProductTotal($product);
            } else {
                Product::whereKey($product->id)->update(['stock_quantity' => $balanceAfter]);
                $product->stock_quantity = $balanceAfter;
            }

            return $movement;
        });
    }

    /**
     * Take units off the shelf for a sale.
     *
     * @throws StorefrontException when the units are no longer available
     */
    public function sell(Product $product, ?ProductVariant $variant, int $quantity, ?Model $order = null): StockMovement
    {
        $this->assertSellable($product, $variant);

        return $this->record($product, $variant, -abs($quantity), StockMovement::SALE, [
            'reference' => $order,
            // A customer checkout has no admin author, even if an admin is browsing.
            'user_id' => null,
        ]);
    }

    /** Put reserved units back after a cancellation. */
    public function releaseToShelf(Product $product, ?ProductVariant $variant, int $quantity, ?Model $order = null, ?string $note = null): StockMovement
    {
        return $this->record($product, $variant, abs($quantity), StockMovement::CANCELLATION, [
            'reference' => $order,
            'note' => $note,
        ]);
    }

    /** Book a delivery from a supplier. This is the only way stock enters. */
    public function receive(array $header, array $lines, ?int $userId = null): StockReceipt
    {
        $lines = array_values(array_filter($lines, fn ($l) => (int) ($l['quantity'] ?? 0) > 0));

        if ($lines === []) {
            throw new StorefrontException(
                'Add at least one product with a quantity before receiving stock.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        return DB::transaction(function () use ($header, $lines, $userId) {
            $receipt = StockReceipt::create([
                'reference' => $header['reference'] ?? $this->generateReceiptReference(),
                'supplier_name' => $header['supplier_name'] ?? null,
                'invoice_number' => $header['invoice_number'] ?? null,
                'received_on' => $header['received_on'] ?? now()->toDateString(),
                'note' => $header['note'] ?? null,
                'user_id' => $userId ?? Auth::id(),
            ]);

            $totalQty = 0;
            $totalCost = 0.0;

            foreach ($lines as $line) {
                [$product, $variant] = $this->resolveUnit(
                    (int) $line['product_id'],
                    isset($line['product_variant_id']) ? (int) $line['product_variant_id'] : null
                );

                $quantity = (int) $line['quantity'];
                $unitCost = isset($line['unit_cost']) && $line['unit_cost'] !== '' ? (float) $line['unit_cost'] : null;

                StockReceiptItem::create([
                    'stock_receipt_id' => $receipt->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                ]);

                $this->record($product, $variant, $quantity, StockMovement::PURCHASE, [
                    'reference' => $receipt,
                    'unit_cost' => $unitCost,
                    'user_id' => $userId ?? Auth::id(),
                    'note' => $header['supplier_name'] ?? null,
                ]);

                $totalQty += $quantity;
                $totalCost += $unitCost !== null ? $unitCost * $quantity : 0.0;
            }

            $receipt->update([
                'total_quantity' => $totalQty,
                'total_cost' => round($totalCost, 2),
            ]);

            return $receipt->load('items.product', 'items.variant');
        });
    }

    /**
     * A counted correction: breakage, theft, a stock-take that disagrees.
     *
     * Deliberately not an absolute number — the admin states the change and why,
     * so the ledger keeps explaining the balance.
     */
    public function adjust(
        Product $product,
        ?ProductVariant $variant,
        int $delta,
        string $reason,
        ?string $note = null,
        ?int $userId = null
    ): StockMovement {
        if (! array_key_exists($reason, self::ADJUSTMENT_REASONS)) {
            throw new StorefrontException(
                'Choose a reason for this stock adjustment.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        if ($reason === 'other' && blank($note)) {
            throw new StorefrontException(
                'Explain the adjustment in the note when the reason is "Other".',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        return $this->record($product, $variant, $delta, StockMovement::ADJUSTMENT, [
            'reason' => $reason,
            'note' => $note,
            'user_id' => $userId ?? Auth::id(),
        ]);
    }

    /** Current on-hand for one stock unit, read straight from the cached balance. */
    public function onHand(Product $product, ?ProductVariant $variant = null): int
    {
        return $variant
            ? (int) ProductVariant::whereKey($variant->id)->value('stock_quantity')
            : (int) Product::whereKey($product->id)->value('stock_quantity');
    }

    /**
     * Recompute the ledger balance from scratch and compare it to the cache.
     *
     * Nothing should ever disagree; this exists so a drift can be detected rather
     * than discovered by a customer buying something that is not there.
     *
     * @return array{expected:int, actual:int, drifted:bool}
     */
    public function verify(Product $product, ?ProductVariant $variant = null): array
    {
        // On a variant product the parent's quantity is a derived sum, not a
        // ledger balance of its own, so it is checked against the variants.
        if ($variant === null && $product->has_variants) {
            $expected = (int) ProductVariant::where('product_id', $product->id)
                ->where('is_active', true)
                ->sum('stock_quantity');
        } else {
            $expected = (int) StockMovement::forUnit($product->id, $variant?->id)->sum('quantity');
        }

        $actual = $this->onHand($product, $variant);

        return [
            'expected' => $expected,
            'actual' => $actual,
            'drifted' => $expected !== $actual,
        ];
    }

    /**
     * Keep the parent product's cached quantity equal to the sum of its variants,
     * so listings, low-stock reports and "in stock" badges stay truthful without
     * every caller having to know whether a product has variants.
     */
    public function syncProductTotal(Product $product): void
    {
        if (! $product->has_variants) {
            return;
        }

        $total = (int) ProductVariant::where('product_id', $product->id)
            ->where('is_active', true)
            ->sum('stock_quantity');

        Product::whereKey($product->id)->update(['stock_quantity' => $total]);
        $product->stock_quantity = $total;
    }

    /**
     * Seed the ledger for stock that predates it, so balances stay explainable.
     */
    public function recordOpeningBalance(Product $product, ?ProductVariant $variant, int $quantity, ?int $userId = null): ?StockMovement
    {
        if ($quantity === 0) {
            return null;
        }

        // The balance is already on the row; write the history without moving it.
        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'quantity' => $quantity,
            'type' => StockMovement::OPENING,
            'balance_after' => $quantity,
            'note' => 'Balance carried over when stock tracking was introduced',
            'user_id' => $userId,
        ]);

        return $movement;
    }

    /**
     * Resolve a product/variant pair, refusing combinations that cannot hold stock.
     */
    public function resolveUnit(int $productId, ?int $variantId): array
    {
        $product = Product::find($productId);

        if (! $product) {
            throw new StorefrontException('That product no longer exists.', 404, ApiCode::NOT_FOUND);
        }

        if ($variantId) {
            $variant = ProductVariant::where('product_id', $productId)->find($variantId);

            if (! $variant) {
                throw new StorefrontException(
                    'That option is not available for this product.',
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }

            return [$product, $variant];
        }

        if ($product->has_variants) {
            throw new StorefrontException(
                "Choose an option for {$product->name} — stock is held per option.",
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        return [$product, null];
    }

    private function assertSellable(Product $product, ?ProductVariant $variant): void
    {
        if (! $product->is_active) {
            throw StorefrontException::unavailable($product->name);
        }

        if ($variant && ! $variant->is_active) {
            throw StorefrontException::unavailable($this->unitName($product, $variant));
        }
    }

    private function unitName(Product $product, ?ProductVariant $variant): string
    {
        return $variant ? "{$product->name} ({$variant->name})" : $product->name;
    }

    private function generateReceiptReference(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = 'GRN-'.strtoupper(Str::random(8));

            if (! StockReceipt::where('reference', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'GRN-'.strtoupper(Str::random(8)).'-'.now()->format('Hisv');
    }
}
