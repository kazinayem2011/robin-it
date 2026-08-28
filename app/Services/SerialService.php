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
     * Put units back on the shelf when an order comes back.
     */
    public function returnFromOrder(Order $order): int
    {
        return ProductSerial::where('order_id', $order->id)
            ->where('status', ProductSerial::SOLD)
            ->update([
                'status' => ProductSerial::RETURNED,
                'sold_at' => null,
                'warranty_until' => null,
            ]);
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
