<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockTake;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Counting a branch's shelves, and correcting what the count disagrees with.
 *
 * Every correction still goes through StockService, which stays the only thing
 * that writes a balance. This does not touch stock itself; it decides which
 * corrections a count implies and records them as belonging to one count.
 */
class StockTakeService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * What is on the books at this branch, to count against.
     *
     * Only what the branch actually holds, plus anything it has held before —
     * a product that has never been at this showroom is not something to walk
     * past and count as zero.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function sheetFor(Store $store, string $search = ''): Collection
    {
        $costs = $this->stock->latestUnitCosts();

        return ProductStock::where('store_id', $store->id)
            ->with([
                // No sku on products — only variants carry one.
                'product:id,name,has_variants,is_active',
                'variant:id,name,sku',
            ])
            ->get()
            ->filter(fn ($row) => $row->product && $row->product->is_active)
            ->when($search !== '', fn ($rows) => $rows->filter(
                fn ($row) => str_contains(mb_strtolower($row->product->name), mb_strtolower($search))
            ))
            ->map(fn ($row) => [
                'product_id' => $row->product_id,
                'product_variant_id' => $row->product_variant_id,
                'name' => $row->variant
                    ? "{$row->product->name} ({$row->variant->name})"
                    : $row->product->name,
                'sku' => $row->variant?->sku,
                'system_quantity' => (int) $row->quantity,
                // So the screen can price a discrepancy as it is typed.
                'unit_cost' => $costs[$row->product_id.':'.($row->product_variant_id ?: '')] ?? null,
            ])
            ->sortBy('name')
            ->values();
    }

    /**
     * Apply a count.
     *
     * All or nothing: a count half-applied is worse than one not applied at
     * all, because nobody can tell which half is real. Lines that agree with
     * the books write nothing — a movement that changes no balance is noise in
     * the ledger, and the count itself records how many were checked.
     *
     * @param  array<int, array{product_id:int, product_variant_id:?int, counted_quantity:int}>  $lines
     */
    public function apply(Store $store, User $user, array $lines, ?string $note = null): StockTake
    {
        if ($lines === []) {
            throw new StorefrontException(
                'Count at least one product before saving.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        return DB::transaction(function () use ($store, $user, $lines, $note) {
            $take = StockTake::create([
                'reference' => StockTake::nextReference(),
                'store_id' => $store->id,
                'user_id' => $user->id,
                'counted_by_name' => $user->name,
                'note' => $note,
            ]);

            $costs = $this->stock->latestUnitCosts();
            $counted = 0;
            $changed = 0;
            $netUnits = 0;
            $valueChange = 0.0;

            foreach ($lines as $line) {
                $counted++;

                [$product, $variant] = $this->stock->resolveUnit(
                    (int) $line['product_id'],
                    $line['product_variant_id'] ?? null
                );

                $onBooks = $this->onBooks($store, $product, $variant);
                $delta = ((int) $line['counted_quantity']) - $onBooks;

                if ($delta === 0) {
                    continue;
                }

                $this->stock->adjust(
                    $product,
                    $variant,
                    $delta,
                    'stock_take',
                    "Counted {$line['counted_quantity']} against {$onBooks} on the books ({$take->reference}).",
                    $user->id,
                    $store->id,
                    $take
                );

                $changed++;
                $netUnits += $delta;

                $cost = $costs[$product->id.':'.($variant?->id ?: '')] ?? null;

                if ($cost !== null) {
                    $valueChange += $cost * $delta;
                }
            }

            $take->update([
                'lines_counted' => $counted,
                'lines_changed' => $changed,
                'net_units' => $netUnits,
                'value_change' => round($valueChange, 2),
            ]);

            return $take->fresh(['store', 'user']);
        });
    }

    /**
     * What the books say this branch holds, read inside the transaction.
     *
     * Not the figure the counter's browser was given: somebody may have sold
     * one while the count was being typed, and the correction has to be
     * against what is true when it is written, or the sale is silently undone.
     */
    private function onBooks(Store $store, Product $product, $variant): int
    {
        return (int) ProductStock::where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->value('quantity');
    }
}
