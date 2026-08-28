<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Jobs\NotifyBackInStock;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Models\Store;
use App\Models\Supplier;
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
        // Every movement happens somewhere. Unless told otherwise it is the
        // branch online orders are picked from, which is where stock lived
        // before branches existed.
        $storeId = $meta['store_id'] ?? Store::onlineFulfilment()?->id;

        if (! in_array($type, StockMovement::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown stock movement type: {$type}");
        }

        if ($delta === 0) {
            throw new \InvalidArgumentException('A stock movement must change the balance.');
        }

        return DB::transaction(function () use ($product, $variant, $delta, $type, $meta, $storeId) {
            // Lock the row that owns the balance so a concurrent sale cannot read
            // the same "before" value and write a conflicting "after".
            $current = $variant
                ? (int) ProductVariant::whereKey($variant->id)->lockForUpdate()->value('stock_quantity')
                : (int) Product::whereKey($product->id)->lockForUpdate()->value('stock_quantity');

            // The branch balance is locked too: the shop-wide total can be
            // fine while the branch being drawn from is empty.
            $branchBefore = $storeId
                ? $this->lockBranchBalance($product->id, $variant?->id, $storeId)
                : null;

            /*
             * Only a sale may take a balance negative, and only on a product
             * set up for pre-order. Everything else — adjustments, transfers,
             * write-offs — still refuses: you cannot write off or move units
             * that are not there, whatever the product's settings say.
             */
            $mayGoNegative = $type === StockMovement::SALE;

            if ($branchBefore !== null
                && $branchBefore + $delta < 0
                && ! ($mayGoNegative && $product->allowsBalance($branchBefore + $delta))
            ) {
                throw StorefrontException::outOfStock(
                    $this->unitName($product, $variant),
                    max(0, $branchBefore)
                );
            }

            $balanceAfter = $current + $delta;

            if ($balanceAfter < 0
                && ! ($mayGoNegative && $product->allowsBalance($balanceAfter))
            ) {
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
                'store_id' => $storeId,
                'reference_type' => $reference instanceof Model ? $reference->getMorphClass() : null,
                'reference_id' => $reference instanceof Model ? $reference->getKey() : null,
                'reason' => $meta['reason'] ?? null,
                'note' => $meta['note'] ?? null,
                'unit_cost' => $meta['unit_cost'] ?? null,
                // Customer-driven movements have no admin behind them.
                'user_id' => $meta['user_id'] ?? Auth::id(),
            ]);

            if ($storeId) {
                ProductStock::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'store_id' => $storeId,
                    ],
                    ['quantity' => $branchBefore + $delta]
                );
            }

            if ($variant) {
                ProductVariant::whereKey($variant->id)->update(['stock_quantity' => $balanceAfter]);
                $variant->stock_quantity = $balanceAfter;
                $this->syncProductTotal($product);
            } else {
                Product::whereKey($product->id)->update(['stock_quantity' => $balanceAfter]);
                $product->stock_quantity = $balanceAfter;
            }

            // Crossing back above zero is the moment anyone waiting wants to
            // hear about. Queued after commit so the mail cannot roll the
            // stock movement back, and so a thirty-line delivery does not sit
            // waiting on thirty batches of email.
            if ($current <= 0 && $balanceAfter > 0) {
                NotifyBackInStock::dispatch($product->id, $variant?->id)->afterCommit();
            }

            return $movement;
        });
    }

    /**
     * Read and lock one branch's balance so two sales cannot both spend it.
     */
    private function lockBranchBalance(int $productId, ?int $variantId, int $storeId): int
    {
        $existing = ProductStock::where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->where('store_id', $storeId)
            ->lockForUpdate()
            ->first();

        return (int) ($existing->quantity ?? 0);
    }

    /**
     * Take units off the shelf for a sale.
     *
     * @throws StorefrontException when the units are no longer available
     */
    public function sell(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        ?Model $order = null,
        ?int $storeId = null
    ): StockMovement {
        $this->assertSellable($product, $variant);

        return $this->record($product, $variant, -abs($quantity), StockMovement::SALE, [
            'reference' => $order,
            // A customer checkout has no admin author, even if an admin is browsing.
            'user_id' => null,
            'store_id' => $storeId ?? Store::onlineFulfilment()?->id,
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
            $supplier = $this->resolveSupplier($header);

            $receipt = StockReceipt::create([
                'reference' => $header['reference'] ?? $this->generateReceiptReference(),
                'supplier_id' => $supplier?->id,
                // Kept alongside the relation so a delivery still names its
                // supplier if that record is later removed.
                'supplier_name' => $supplier?->name ?? ($header['supplier_name'] ?? null),
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
                    'store_id' => $header['store_id'] ?? null,
                    'unit_cost' => $unitCost,
                    'user_id' => $userId ?? Auth::id(),
                    'note' => $receipt->supplier_name,
                ]);

                $totalQty += $quantity;
                $totalCost += $unitCost !== null ? $unitCost * $quantity : 0.0;
            }

            $receipt->update([
                'total_quantity' => $totalQty,
                'total_cost' => round($totalCost, 2),
            ]);

            return $receipt->load('items.product', 'items.variant', 'supplier');
        });
    }

    /**
     * Which supplier a delivery came from.
     *
     * Accepts an id from the dropdown, or a name typed into it — a new supplier
     * being added mid-delivery is normal, and refusing it would push the admin
     * out to another screen and lose the half-entered receipt.
     */
    private function resolveSupplier(array $header): ?Supplier
    {
        if (! empty($header['supplier_id'])) {
            return Supplier::find($header['supplier_id']);
        }

        $name = trim((string) ($header['supplier_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        // Match case-insensitively so "Star Tech" does not become a second
        // supplier alongside "star tech".
        $existing = Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        return $existing ?? Supplier::create(['name' => $name, 'is_active' => true]);
    }

    /**
     * Move units from one branch to another.
     *
     * Written as two movements that net to exactly zero, so the shop's total
     * holding is unchanged and the ledger explains where the units went. The
     * outbound leg is written first: if the origin cannot cover it the whole
     * thing fails before anything has been credited to the destination.
     *
     * @return array{0: StockMovement, 1: StockMovement}
     *
     * @throws StorefrontException
     */
    public function transfer(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        int $fromStoreId,
        int $toStoreId,
        ?string $note = null,
        ?int $userId = null
    ): array {
        $quantity = abs($quantity);

        if ($quantity === 0) {
            throw new StorefrontException(
                'Enter how many units to move.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        if ($fromStoreId === $toStoreId) {
            throw new StorefrontException(
                'Choose two different branches.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $from = Store::find($fromStoreId);
        $to = Store::find($toStoreId);

        if (! $from || ! $to || ! $from->holds_stock || ! $to->holds_stock) {
            throw new StorefrontException(
                'Both branches must be ones that hold stock.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $label = trim(($note ? $note.' ' : '')."({$from->name} → {$to->name})");

        return DB::transaction(function () use ($product, $variant, $quantity, $fromStoreId, $toStoreId, $label, $userId) {
            $out = $this->record($product, $variant, -$quantity, StockMovement::TRANSFER, [
                'store_id' => $fromStoreId,
                'note' => $label,
                'user_id' => $userId,
            ]);

            $in = $this->record($product, $variant, $quantity, StockMovement::TRANSFER, [
                'store_id' => $toStoreId,
                'note' => $label,
                'user_id' => $userId,
            ]);

            return [$out, $in];
        });
    }

    /**
     * How much of something each branch is holding.
     *
     * @return array<int, array{store_id:int, store:string, quantity:int}>
     */
    public function branchBreakdown(Product $product, ?ProductVariant $variant = null): array
    {
        return ProductStock::forUnit($product->id, $variant?->id)
            ->with('store:id,name,city,is_active,holds_stock')
            ->get()
            ->filter(fn ($row) => $row->store && $row->store->is_active)
            ->sortBy(fn ($row) => $row->store->name)
            ->map(fn ($row) => [
                'store_id' => $row->store_id,
                'store' => $row->store->name,
                'city' => $row->store->city,
                'quantity' => $row->quantity,
            ])
            ->values()
            ->all();
    }

    /**
     * A counted correction: breakage, theft, a stock-take that disagrees.
     *
     * Deliberately not an absolute number — the admin states the change and why,
     * so the ledger keeps explaining the balance.
     */
    /**
     * @param  Model|null  $reference  what this correction belongs to — a stock
     *                                 take, so a hundred lines counted in one
     *                                 morning read as one count rather than a
     *                                 hundred unexplained corrections
     */
    public function adjust(
        Product $product,
        ?ProductVariant $variant,
        int $delta,
        string $reason,
        ?string $note = null,
        ?int $userId = null,
        ?int $storeId = null,
        ?Model $reference = null
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
            'store_id' => $storeId,
            'reference' => $reference,
        ]);
    }

    /**
     * What the shop last paid for one unit of something.
     *
     * The most recent purchase price, not the retail price — this is what the
     * stock cost. Null when the unit has never come in through a delivery, in
     * which case the cost is genuinely unknown and must not be guessed at.
     */
    public function latestUnitCost(Product $product, ?ProductVariant $variant = null): ?float
    {
        // Newest first here: one row is wanted and it must be the last price
        // paid, not the first.
        $cost = $this->costQuery()
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->reorder('id', 'desc')
            ->value('unit_cost');

        return $cost === null ? null : (float) $cost;
    }

    /**
     * The same figure for every stock unit at once, keyed "productId:variantId".
     *
     * For the valuation on the stock screen, which would otherwise ask per row.
     *
     * @return array<string, float>
     */
    public function latestUnitCosts(): array
    {
        return $this->costQuery()
            ->select('product_id', 'product_variant_id', 'unit_cost')
            ->orderBy('id')
            ->get()
            ->reduce(function (array $carry, StockMovement $movement) {
                $carry[$movement->product_id.':'.($movement->product_variant_id ?? '')] = (float) $movement->unit_cost;

                return $carry;
            }, []);
    }

    /**
     * Costed movements, oldest first — latestUnitCosts() reduces over them so
     * the most recent price is the one that survives.
     */
    private function costQuery()
    {
        return StockMovement::query()
            ->whereNotNull('unit_cost')
            ->orderBy('id');
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
