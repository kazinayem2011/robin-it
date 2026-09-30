<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;

/**
 * Which lines of an order were sold ahead of the delivery.
 *
 * The answer lives in the ledger: the SALE movement an order wrote carries the
 * balance it left, and a balance below zero means units owed. OrderItem asked
 * that one line at a time, which is one query per line; a list of orders, each
 * with its lines, now marked on every screen, would be a query per line of
 * every order on it. This reads an order's sales once and answers for all of
 * its lines.
 *
 * Bound scoped, so it lives for one request or one job and cannot carry an
 * answer from one into the next. StockService forgets an order as soon as it
 * writes a new sale for it, so an order edited mid-request is read afresh.
 */
class PreorderLedger
{
    /** @var array<int, array<string, int>> order id → "product:variant" → last balance */
    private array $balances = [];

    /** @var array<int, array<string, bool>> order id → "product:variant" → owed beyond stock */
    private array $backorders = [];

    /** @var array<int, array<string, bool>> order id → "product:variant" → still short now */
    private array $shortNow = [];

    /** @var array<int, array<string, array<int, int>>> order id → "product:variant" → branch ids short now */
    private array $shortAt = [];

    /**
     * Owed because the order took more than was in stock, rather than as a
     * pre-order — and still owed now: "waiting for stock" on the line.
     *
     * Now, not when it was sold. A delivery landing at the branch, or the
     * admin filling the line from another that has it, ends the wait, and the
     * line has to stop saying so; it was fixed at the moment of sale.
     */
    public function waitingForStock(int $orderId, int $productId, ?int $variantId): bool
    {
        $key = self::key($productId, $variantId);
        $this->balances[$orderId] ??= $this->read($orderId);

        return ($this->backorders[$orderId][$key] ?? false)
            && $this->stillShort($orderId, $key);
    }

    /**
     * Ships later: a pre-order line (as sold), or one still waiting for stock.
     */
    public function wasPreordered(int $orderId, int $productId, ?int $variantId): bool
    {
        $key = self::key($productId, $variantId);
        $this->balances[$orderId] ??= $this->read($orderId);

        $balance = $this->balances[$orderId][$key] ?? null;

        if ($balance === null || $balance >= 0) {
            return false;
        }

        // Taken past stock rather than pre-ordered: only while still owed.
        if ($this->backorders[$orderId][$key] ?? false) {
            return $this->stillShort($orderId, $key);
        }

        return true;
    }

    /**
     * Units on this line are owed right now — a pre-order whose delivery has
     * not landed, or an order taken past stock — so it cannot leave yet.
     *
     * Unlike wasPreordered(), a pre-order stops counting once the stock is in:
     * that one keeps the "pre-order" tag as sold, this one asks what is on the
     * shelf now.
     */
    public function stillOwed(int $orderId, int $productId, ?int $variantId): bool
    {
        $key = self::key($productId, $variantId);
        $this->balances[$orderId] ??= $this->read($orderId);

        $balance = $this->balances[$orderId][$key] ?? null;

        return $balance !== null && $balance < 0 && $this->stillShort($orderId, $key);
    }

    /**
     * The branches a line's units are owed at now, by name.
     *
     * So the refusal to ship can say where the unit is missing: a delivery
     * received into another branch leaves the order just as short, and "not
     * in stock yet" read as wrong to the person who had just received it.
     *
     * @return array<int, string>
     */
    public function shortBranches(int $orderId, int $productId, ?int $variantId): array
    {
        $key = self::key($productId, $variantId);
        $this->stillShort($orderId, $key);

        $ids = $this->shortAt[$orderId][$key] ?? [];

        return $ids === [] ? [] : Store::whereIn('id', $ids)->orderBy('id')->pluck('name')->all();
    }

    public function forget(int $orderId): void
    {
        unset($this->balances[$orderId], $this->backorders[$orderId], $this->shortNow[$orderId], $this->shortAt[$orderId]);
    }

    /**
     * Whether any branch this line's units are held at is below zero now —
     * units owed there, not yet delivered. Read once per order, for all lines.
     */
    private function stillShort(int $orderId, string $key): bool
    {
        if (! isset($this->shortNow[$orderId])) {
            $held = StockMovement::where('reference_type', Order::class)
                ->where('reference_id', $orderId)
                ->where('type', '!=', StockMovement::WRITE_OFF)
                ->whereNotNull('store_id')
                ->groupBy('product_id', 'product_variant_id', 'store_id')
                ->selectRaw('product_id, product_variant_id, store_id, SUM(quantity) as net')
                ->get()
                ->filter(fn ($row) => (int) $row->net < 0);

            $levels = $held->isEmpty() ? collect() : ProductStock::query()
                ->whereIn('product_id', $held->pluck('product_id')->unique())
                ->whereIn('store_id', $held->pluck('store_id')->unique())
                ->get(['product_id', 'product_variant_id', 'store_id', 'quantity']);

            $short = [];
            $at = [];

            foreach ($held as $row) {
                $level = $levels->first(fn ($l) => (int) $l->product_id === (int) $row->product_id
                    && (int) $l->store_id === (int) $row->store_id
                    && (int) $l->product_variant_id === (int) $row->product_variant_id);

                if ($level && (int) $level->quantity < 0) {
                    $unit = self::key((int) $row->product_id, $row->product_variant_id ? (int) $row->product_variant_id : null);
                    $short[$unit] = true;
                    $at[$unit][] = (int) $row->store_id;
                }
            }

            $this->shortNow[$orderId] = $short;
            $this->shortAt[$orderId] = $at;
        }

        if (isset($this->shortNow[$orderId][$key])) {
            return true;
        }

        // No branches set up: the unit's shop-wide count says it.
        if (! Store::query()->exists()) {
            [$productId, $variantId] = explode(':', $key);

            $count = $variantId === '-'
                ? Product::whereKey($productId)->value('stock_quantity')
                : ProductVariant::whereKey($variantId)->value('stock_quantity');

            return (int) $count < 0;
        }

        return false;
    }

    /** @return array<string, int> */
    private function read(int $orderId): array
    {
        $balances = [];

        // Oldest first, so a later sale for the same unit (an edit adding
        // more) leaves its balance as the one that counts, as before.
        StockMovement::where('reference_type', Order::class)
            ->where('reference_id', $orderId)
            ->where('type', StockMovement::SALE)
            ->orderBy('id')
            ->get(['product_id', 'product_variant_id', 'balance_after', 'reason'])
            ->each(function ($movement) use (&$balances, $orderId) {
                $key = self::key($movement->product_id, $movement->product_variant_id);
                $balances[$key] = (int) $movement->balance_after;

                if ($movement->reason === StockMovement::REASON_BACKORDER) {
                    $this->backorders[$orderId][$key] = true;
                }
            });

        return $balances;
    }

    private static function key(int $productId, ?int $variantId): string
    {
        return $productId.':'.($variantId ?? '-');
    }
}
