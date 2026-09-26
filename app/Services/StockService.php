<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Jobs\NotifyBackInStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Models\Store;
use App\Models\Supplier;
use App\Support\PreorderLedger;
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

            // Units taken beyond stock on a product that takes orders past it
            // (sellForOrder decided that, with the whole shop's holding in view).
            $owed = $mayGoNegative && ! empty($meta['owed']);

            if ($branchBefore !== null
                && $branchBefore + $delta < 0
                && ! $owed
                && ! ($mayGoNegative && $product->allowsBalance($branchBefore + $delta))
            ) {
                throw StorefrontException::outOfStock(
                    $this->unitName($product, $variant),
                    max(0, $branchBefore)
                );
            }

            $balanceAfter = $current + $delta;

            if ($balanceAfter < 0
                && ! $owed
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

            // An order's pre-order marks are read from its sales; this one
            // may change them, so the next read goes back to the ledger.
            if ($reference instanceof Order) {
                app(PreorderLedger::class)->forget((int) $reference->getKey());
            }

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

            /*
             * Falling to the reorder level tells whoever buys stock, once.
             *
             * On the crossing, not on the state: a shelf that sits below its
             * level all week would otherwise send a message every time
             * anything moved, and a bell that rings hourly is a bell nobody
             * reads. After commit, so nobody is told about a movement that
             * then rolled back.
             */
            $level = $product->reorderLevel();

            if ($level > 0 && $current > $level && $balanceAfter <= $level) {
                DB::afterCommit(fn () => app(ShopNotifier::class)
                    ->stockRanLow($product, $variant, $balanceAfter));
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
        ?int $storeId = null,
        // Beyond stock, on a product that takes orders past it: marked, so
        // the order line says "waiting for stock" rather than "pre-order".
        bool $owed = false,
    ): StockMovement {
        $this->assertSellable($product, $variant);

        return $this->record($product, $variant, -abs($quantity), StockMovement::SALE, [
            'reference' => $order,
            // A customer checkout has no admin author, even if an admin is browsing.
            'user_id' => null,
            'store_id' => $storeId ?? Store::onlineFulfilment()?->id,
        ] + ($owed ? ['owed' => true, 'reason' => StockMovement::REASON_BACKORDER] : []));
    }

    /**
     * Take an order line's units off the shelves that hold them.
     *
     * The branch it ships from is chosen here, not fixed: the one asked for
     * (a counter sale at a showroom), else the default online branch, else
     * each other branch in the shop's order — as many as it takes. It was
     * always the one online branch, so a laptop sitting in a showroom showed
     * "In Stock", went into the cart, and was refused at checkout.
     *
     * A line can come from two branches; the ledger records each part where it
     * was taken, which is what a cancellation, a return or a change of branch
     * later reads (branchesHolding). What no branch has is owed by the first
     * one — allowed only on a pre-order product, which record() enforces.
     *
     * @return array<int, int> store id => units taken there
     */
    public function sellForOrder(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        Order $order,
        ?int $preferredStoreId = null,
    ): array {
        $this->assertSellable($product, $variant);

        $branches = $this->branchesInPickingOrder($preferredStoreId);

        if ($branches === []) {
            // No branch set up at all: the shop-wide balance, as before —
            // owing what is short, where the product takes orders past stock.
            $onHand = (int) ($variant?->stock_quantity ?? $product->stock_quantity);
            $owed = $onHand < abs($quantity) && $product->takesOrdersBeyondStock($onHand);

            $this->sell($product, $variant, $quantity, $order, null, $owed);

            return [];
        }

        $held = ProductStock::forUnit($product->id, $variant?->id)
            ->whereIn('store_id', $branches)
            ->pluck('quantity', 'store_id');

        $taken = [];
        $left = abs($quantity);

        foreach ($branches as $storeId) {
            $here = min($left, max(0, (int) ($held[$storeId] ?? 0)));

            if ($here > 0) {
                $this->sell($product, $variant, $here, $order, $storeId);
                $taken[$storeId] = $here;
                $left -= $here;
            }

            if ($left === 0) {
                return $taken;
            }
        }

        /*
         * Owed, at the first branch: on a pre-order product within its limit
         * (record() checks), or on any product with some in stock across the
         * shop, which takes the order and flags it "waiting for stock".
         */
        $onHand = (int) collect($held)->filter(fn ($q) => $q > 0)->sum();
        $owed = $product->takesOrdersBeyondStock($onHand);

        $this->sell($product, $variant, $left, $order, $branches[0], $owed);
        $taken[$branches[0]] = ($taken[$branches[0]] ?? 0) + $left;

        return $taken;
    }

    /**
     * Where an order's units stand, per branch, for each thing it bought.
     *
     * Read from the ledger rather than kept in a column of its own, so it
     * cannot drift from what happened: every movement tied to the order —
     * the sale, a change of branch, a cancellation or return — nets out per
     * branch. A write-off is left out: a damaged return is still a unit the
     * customer no longer has.
     *
     * @return array<string, array<int, int>> "product:variant" => [store id => units]
     */
    public function branchesHolding(Order $order): array
    {
        $rows = StockMovement::query()
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->getKey())
            ->where('type', '!=', StockMovement::WRITE_OFF)
            ->whereNotNull('store_id')
            ->groupBy('product_id', 'product_variant_id', 'store_id')
            ->selectRaw('product_id, product_variant_id, store_id, SUM(quantity) as net')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $units = -(int) $row->net;

            if ($units > 0) {
                $out[self::unitKey((int) $row->product_id, $row->product_variant_id)][(int) $row->store_id] = $units;
            }
        }

        return $out;
    }

    /**
     * Put an order's units back where they came from.
     *
     * The branch each unit left is read from the ledger, most first, so a
     * cancellation undoes the sale exactly. A return may name a branch
     * instead — the parcel came back to a different shop than it left.
     *
     * @return array<int, int> store id => units put back there
     */
    public function restoreForOrder(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        Order $order,
        string $type,
        ?string $note = null,
        ?int $toStoreId = null,
    ): array {
        $left = abs($quantity);

        if ($left === 0) {
            return [];
        }

        $meta = ['reference' => $order, 'note' => $note];

        if ($toStoreId) {
            $this->record($product, $variant, $left, $type, $meta + ['store_id' => $toStoreId]);

            return [$toStoreId => $left];
        }

        $holding = $this->branchesHolding($order)[self::unitKey($product->id, $variant?->id)] ?? [];
        arsort($holding);

        $put = [];

        foreach ($holding as $storeId => $units) {
            $here = min($left, $units);
            $this->record($product, $variant, $here, $type, $meta + ['store_id' => $storeId]);
            $put[$storeId] = $here;
            $left -= $here;

            if ($left === 0) {
                return $put;
            }
        }

        // Nothing in the ledger says where (an order from before branches):
        // the default branch, as it always was.
        $this->record($product, $variant, $left, $type, $meta);
        $default = Store::onlineFulfilment()?->id;

        if ($default) {
            $put[$default] = ($put[$default] ?? 0) + $left;
        }

        return $put;
    }

    /**
     * Have an order ship from one branch, moving its units there.
     *
     * Nothing moves physically; the order just draws on another branch. It is
     * written as a transfer tied to the order — out of the new branch, back
     * into the old — so the shelves' balances and branchesHolding() both say
     * where the units now are. Refused when the branch does not have them.
     */
    public function moveOrderTo(Order $order, int $storeId): int
    {
        $target = Store::holdsStock()->whereKey($storeId)->first();

        if (! $target) {
            throw new StorefrontException('That branch does not hold stock.', 422, ApiCode::VALIDATION_ERROR);
        }

        return DB::transaction(function () use ($order, $target) {
            $moved = 0;
            $note = "Order {$order->order_number} now ships from {$target->name}.";

            foreach ($this->branchesHolding($order) as $key => $stores) {
                [$productId, $variantId] = self::splitKey($key);
                [$product, $variant] = $this->resolveUnit($productId, $variantId);

                foreach ($stores as $storeId => $units) {
                    if ($storeId === $target->id) {
                        continue;
                    }

                    $meta = ['reference' => $order, 'note' => $note];
                    $this->record($product, $variant, -$units, StockMovement::TRANSFER, $meta + ['store_id' => $target->id]);
                    $this->record($product, $variant, $units, StockMovement::TRANSFER, $meta + ['store_id' => $storeId]);
                    $moved += $units;
                }
            }

            return $moved;
        });
    }

    /**
     * Set where one of an order's lines comes from: [store id => units].
     *
     * The admin's per-item choice — the laptop from Khulna, the mouse from
     * Dhaka, or a line split two and one. Written like moveOrderTo(): each
     * branch gaining units for the order gives them up (a transfer out tied
     * to the order), each losing them gets them back. Owed units move too,
     * which is how a "waiting for stock" line is filled from a branch that
     * has it. The total cannot change here; that is an edit of the order.
     *
     * @param  array<int, int>  $target
     */
    public function allocateOrderLine(Order $order, Product $product, ?ProductVariant $variant, array $target): void
    {
        $target = array_filter(array_map('intval', $target), fn ($q) => $q > 0);
        $current = $this->branchesHolding($order)[self::unitKey($product->id, $variant?->id)] ?? [];

        if (array_sum($target) !== array_sum($current)) {
            throw new StorefrontException(
                'The branches must add up to '.array_sum($current).' for '.$this->unitName($product, $variant).'.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $branches = Store::holdsStock()->whereIn('id', array_keys($target))->pluck('name', 'id');

        if (count($branches) !== count($target)) {
            throw new StorefrontException('One of those branches does not hold stock.', 422, ApiCode::VALIDATION_ERROR);
        }

        DB::transaction(function () use ($order, $product, $variant, $target, $current, $branches) {
            $note = 'Order '.$order->order_number.' now ships from '.$branches->implode(', ').'.';
            $meta = ['reference' => $order, 'note' => $note];

            // Back first, then taken, so a branch is never briefly short.
            foreach ($current as $storeId => $units) {
                $less = $units - ($target[$storeId] ?? 0);
                if ($less > 0) {
                    $this->record($product, $variant, $less, StockMovement::TRANSFER, $meta + ['store_id' => $storeId]);
                }
            }

            foreach ($target as $storeId => $units) {
                $more = $units - ($current[$storeId] ?? 0);
                if ($more > 0) {
                    $this->record($product, $variant, -$more, StockMovement::TRANSFER, $meta + ['store_id' => $storeId]);
                }
            }
        });
    }

    /**
     * The branch an order mostly ships from, for its label and for where an
     * edit takes more units first. Null for an order with nothing on a shelf.
     */
    public function mainBranchOf(Order $order): ?int
    {
        $totals = [];

        foreach ($this->branchesHolding($order) as $stores) {
            foreach ($stores as $storeId => $units) {
                $totals[$storeId] = ($totals[$storeId] ?? 0) + $units;
            }
        }

        if ($totals === []) {
            return null;
        }

        arsort($totals);

        return (int) array_key_first($totals);
    }

    /**
     * Branches to take from: the one asked for, the online default, then the
     * rest in the shop's own order.
     *
     * @return array<int, int>
     */
    private function branchesInPickingOrder(?int $preferredStoreId): array
    {
        $ids = Store::holdsStock()
            ->orderByDesc('fulfils_online')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($preferredStoreId && in_array($preferredStoreId, $ids, true)) {
            $ids = array_values(array_unique([$preferredStoreId, ...$ids]));
        }

        return $ids;
    }

    private static function unitKey(int $productId, $variantId): string
    {
        return $productId.':'.($variantId ? (int) $variantId : '-');
    }

    /** @return array{0: int, 1: ?int} */
    private static function splitKey(string $key): array
    {
        [$product, $variant] = explode(':', $key);

        return [(int) $product, $variant === '-' ? null : (int) $variant];
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

            /*
             * An opening balance is received like a delivery — same screen,
             * same paperwork, same cost against it — but it is not a purchase
             * and must not read as one in the ledger, in the supplier's
             * history or in what the shop spent this month.
             */
            $movementType = $supplier?->isOpeningBalance()
                ? StockMovement::OPENING
                : StockMovement::PURCHASE;

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

                // Into each branch it was split between, or all of it into the
                // one branch named for the delivery.
                foreach ($this->receivingSplit($line, $quantity, $header['store_id'] ?? null, $product, $variant) as [$storeId, $units]) {
                    $this->record($product, $variant, $units, $movementType, [
                        'reference' => $receipt,
                        'store_id' => $storeId,
                        'unit_cost' => $unitCost,
                        'user_id' => $userId ?? Auth::id(),
                        'note' => $receipt->supplier_name,
                    ]);
                }

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
     * Where a delivered line goes: [[store id, units], …].
     *
     * The client's flow — ten arrive, six to Khulna and four to Dhaka — so a
     * line may name its own split (`branches`: store id => units). It has to
     * add up to what arrived; anything else is a typing slip that would put
     * units nowhere, or out of thin air. Without a split, all of it goes to
     * the branch named for the whole delivery.
     *
     * @return list<array{0: ?int, 1: int}>
     */
    public function receivingSplit(array $line, int $quantity, ?int $deliveryStoreId, Product $product, ?ProductVariant $variant): array
    {
        $split = array_filter(
            array_map('intval', (array) ($line['branches'] ?? [])),
            fn ($units) => $units > 0,
        );

        if ($split === []) {
            return [[$deliveryStoreId, $quantity]];
        }

        $placed = array_sum($split);

        if ($placed !== $quantity) {
            throw new StorefrontException(
                "{$this->unitName($product, $variant)}: {$quantity} arrived, but the branches add up to {$placed}. "
                    .'Make them add up to what arrived.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        // In the branch list's own order — the order the receiving screen
        // shows them — so serials typed in that order land where they went.
        $known = Store::holdsStock()->whereIn('id', array_keys($split))
            ->orderBy('sort_order')->orderBy('name')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach (array_keys($split) as $storeId) {
            if (! in_array((int) $storeId, $known, true)) {
                throw new StorefrontException('One of those branches does not hold stock.', 422, ApiCode::VALIDATION_ERROR);
            }
        }

        return array_map(fn ($storeId) => [$storeId, $split[$storeId]], $known);
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
     *
     * Also puts the units somewhere. A quantity that no branch holds is stock
     * the shop cannot pick, count or transfer: it showed on the product row and
     * nowhere on the branch stock screen, which is how a shop with no purchases
     * ended up with items apparently in stock and an empty History behind them.
     */
    public function recordOpeningBalance(Product $product, ?ProductVariant $variant, int $quantity, ?int $userId = null, ?string $note = null): ?StockMovement
    {
        if ($quantity === 0) {
            return null;
        }

        $store = Store::onlineFulfilment();

        // The balance is already on the row; write the history without moving it.
        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'store_id' => $store?->id,
            'quantity' => $quantity,
            'type' => StockMovement::OPENING,
            'balance_after' => $quantity,
            'note' => $note ?? 'Balance carried over when stock tracking was introduced',
            'user_id' => $userId,
        ]);

        if ($store) {
            ProductStock::updateOrCreate(
                ['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'store_id' => $store->id],
                ['quantity' => $quantity]
            );
        }

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
