<?php

namespace App\Support;

use App\Models\Order;
use App\Models\StockMovement;

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

    public function wasPreordered(int $orderId, int $productId, ?int $variantId): bool
    {
        $this->balances[$orderId] ??= $this->read($orderId);

        $balance = $this->balances[$orderId][self::key($productId, $variantId)] ?? null;

        return $balance !== null && $balance < 0;
    }

    public function forget(int $orderId): void
    {
        unset($this->balances[$orderId]);
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
            ->get(['product_id', 'product_variant_id', 'balance_after'])
            ->each(function ($movement) use (&$balances) {
                $balances[self::key($movement->product_id, $movement->product_variant_id)]
                    = (int) $movement->balance_after;
            });

        return $balances;
    }

    private static function key(int $productId, ?int $variantId): string
    {
        return $productId.':'.($variantId ?? '-');
    }
}
