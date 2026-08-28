<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\StockReceipt;
use Illuminate\Support\Facades\DB;

/**
 * Which physical unit went to which customer.
 *
 * The warranty form asked for a serial number and nothing recorded one, so a
 * claim could not be checked against anything: not whether the unit was bought
 * here, not when its cover started, not whether the same serial had been
 * claimed before.
 *
 * Serials are optional. Most of a computer shop's stock — cables, thermal
 * paste, a bag of screws — has no serial worth keeping, and requiring one
 * would make receiving a delivery a chore nobody completes.
 */
class SerialService
{
    /**
     * Take serials in with a delivery.
     *
     * @param  array<int, string>  $serials
     * @return array{added: int, skipped: array<int, string>}
     */
    public function receive(
        Product $product,
        ?int $variantId,
        array $serials,
        ?int $storeId = null,
        ?StockReceipt $receipt = null,
    ): array {
        $clean = collect($serials)
            ->map(fn ($s) => ProductSerial::normalise($s))
            ->filter()
            ->unique()
            ->values();

        if ($clean->isEmpty()) {
            return ['added' => 0, 'skipped' => []];
        }

        return DB::transaction(function () use ($product, $variantId, $clean, $storeId, $receipt) {
            /*
             * Refused rather than merged. A serial already on the books is
             * either a typo or a supplier shipping a duplicate, and both are
             * worth stopping at the door — the alternative is finding out
             * during a warranty claim, when one of two customers is holding a
             * unit the shop cannot account for.
             */
            $taken = ProductSerial::whereIn('serial', $clean)->pluck('serial')->all();
            $fresh = $clean->reject(fn ($s) => in_array($s, $taken, true));

            foreach ($fresh as $serial) {
                ProductSerial::create([
                    'product_id' => $product->id,
                    'product_variant_id' => $variantId,
                    'serial' => $serial,
                    'store_id' => $storeId,
                    'status' => ProductSerial::IN_STOCK,
                    'stock_receipt_id' => $receipt?->id,
                ]);
            }

            return ['added' => $fresh->count(), 'skipped' => $taken];
        });
    }

    /**
     * Hand the units on an order over to the customer.
     *
     * Oldest first, from the branch the order is going out of. A shop picks
     * what is nearest the front of the shelf, and the oldest unit is the one
     * whose warranty is furthest through — giving it out first is what keeps
     * stock from ageing into unsellability.
     *
     * Silent about products that carry no serials: most of them do not.
     *
     * @return int how many units were tied to this order
     */
    public function assignToOrder(Order $order, ?int $storeId = null): int
    {
        return DB::transaction(function () use ($order, $storeId) {
            $assigned = 0;

            foreach ($order->items as $item) {
                $needed = (int) $item->quantity - $item->serials()->count();

                if ($needed <= 0) {
                    continue;
                }

                $available = ProductSerial::available()
                    ->where('product_id', $item->product_id)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                    ->orderBy('id')
                    ->limit($needed)
                    ->lockForUpdate()
                    ->get();

                $months = $item->product?->warranty_months;

                foreach ($available as $serial) {
                    $serial->forceFill([
                        'status' => ProductSerial::SOLD,
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'sold_at' => now(),
                        // From the day it goes out, which is when the customer
                        // can first use it.
                        'warranty_until' => $months ? now()->addMonths($months)->toDateString() : null,
                    ])->save();

                    $assigned++;
                }
            }

            return $assigned;
        });
    }

    /**
     * Put units back when an order comes back.
     *
     * A return splits: some units are resellable and some are damaged, and the
     * stock ledger already records that difference. The serials have to follow
     * it, or the two disagree.
     *
     * The first version marked every serial on the order as "returned" and left
     * it there. Nothing ever moved it on, so a unit that came back in perfect
     * condition and went straight back on the shelf had a serial no sale could
     * ever pick up again: the stock count said one available, the serial list
     * said none, and the next customer to buy it got no serial recorded.
     *
     * @param  array<int, array{order_item_id?: int, resellable?: int, damaged?: int}>  $lines
     * @return array{restocked: int, written_off: int}
     */
    public function returnFromOrder(Order $order, array $lines = []): array
    {
        return DB::transaction(function () use ($order, $lines) {
            $restocked = 0;
            $writtenOff = 0;

            /*
             * With no breakdown — a caller that does not know, or an older one
             * — everything is treated as resellable. Putting a working unit
             * back is the recoverable mistake; writing off a good one is not.
             */
            if ($lines === []) {
                $restocked = $this->releaseSerials($order, null, PHP_INT_MAX, ProductSerial::IN_STOCK);

                return ['restocked' => $restocked, 'written_off' => 0];
            }

            foreach ($lines as $line) {
                $itemId = $line['order_item_id'] ?? null;

                $restocked += $this->releaseSerials(
                    $order,
                    $itemId,
                    max(0, (int) ($line['resellable'] ?? 0)),
                    ProductSerial::IN_STOCK
                );

                $writtenOff += $this->releaseSerials(
                    $order,
                    $itemId,
                    max(0, (int) ($line['damaged'] ?? 0)),
                    ProductSerial::FAULTY
                );
            }

            return ['restocked' => $restocked, 'written_off' => $writtenOff];
        });
    }

    /**
     * Move some of an order's sold units to a resting state.
     *
     * The note keeps the fact that a unit came back once, which the status no
     * longer says: a resellable return goes straight to "on the shelf",
     * because that is where it is.
     */
    private function releaseSerials(Order $order, ?int $itemId, int $howMany, string $status): int
    {
        if ($howMany <= 0) {
            return 0;
        }

        $units = ProductSerial::where('order_id', $order->id)
            ->where('status', ProductSerial::SOLD)
            ->when($itemId, fn ($q) => $q->where('order_item_id', $itemId))
            ->orderBy('id')
            ->limit($howMany)
            ->lockForUpdate()
            ->get();

        foreach ($units as $unit) {
            $unit->forceFill([
                'status' => $status,
                'order_id' => null,
                'order_item_id' => null,
                'sold_at' => null,
                // The cover ended with the sale it was attached to.
                'warranty_until' => null,
                'note' => trim(($unit->note ? $unit->note.' ' : '')
                    .'Came back on '.$order->order_number.'.'),
            ])->save();
        }

        return $units->count();
    }

    /**
     * What is known about one serial.
     *
     * The question a warranty claim asks: is this ours, who bought it, and is
     * it still covered.
     */
    public function lookup(string $serial): ?ProductSerial
    {
        return ProductSerial::with(['product:id,name,warranty_months', 'variant:id,name', 'order', 'store:id,name'])
            ->where('serial', ProductSerial::normalise($serial))
            ->first();
    }

    /**
     * Mark a unit as written off.
     */
    public function writeOff(ProductSerial $serial, ?string $note = null): ProductSerial
    {
        if ($serial->status === ProductSerial::SOLD) {
            throw new StorefrontException(
                'That unit is with a customer. Take it back on the order first.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $serial->forceFill([
            'status' => ProductSerial::FAULTY,
            'note' => $note,
        ])->save();

        return $serial;
    }

    /**
     * @return array<string, int>
     */
    public function countsFor(OrderItem $item): array
    {
        return [
            'needed' => (int) $item->quantity,
            'assigned' => $item->serials()->count(),
        ];
    }
}
