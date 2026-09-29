<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Models\ProductSerial;
use App\Models\StockMovement;
use App\Models\WarrantyClaim;
use App\Support\BrandDetails;
use App\Support\SmsTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Warranty claims, from the form to the counter.
 *
 * A claim was a typed serial and a status anyone could set to anything. It
 * never looked at the shop's own units, so a claim on a laptop the shop never
 * sold read exactly like one on a laptop sold last week; the customer was told
 * nothing as it moved; and a unit swapped for a new one moved no stock, so the
 * shelf count and the serial list both went wrong.
 */
class WarrantyService
{
    public function __construct(private SerialService $serials) {}

    /**
     * Log a claim from the Warranty page.
     *
     * @param  array<string, mixed>  $details  validated form fields
     *
     * @throws StorefrontException when the serial already has a claim in progress
     */
    public function submit(array $details, ?int $userId): WarrantyClaim
    {
        $serial = ProductSerial::normalise($details['serial_number']);

        /*
         * One claim at a time per unit. A second one split the notes across
         * two tickets, and whichever staff opened first was the one updated.
         */
        $open = WarrantyClaim::where('serial_number', $serial)
            ->whereNotIn('status', WarrantyClaim::FINAL)
            ->value('claim_number');

        if ($open) {
            throw new StorefrontException(
                "This serial already has a claim in progress: {$open}. Track it with that number on this page.",
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $claim = new WarrantyClaim;
        $claim->fill($details);
        $claim->serial_number = $serial;
        $claim->product_serial_id = $this->serials->lookup($serial)?->id;
        $claim->claim_number = $this->claimNumber();
        $claim->user_id = $userId;
        $claim->status = 'received';
        $claim->diagnostic_notes = 'Claim logged. Hardware awaiting intake diagnosis at the '
            .BrandDetails::name().' service lab.';
        $claim->save();

        $this->text('warranty_received', $claim);

        return $claim;
    }

    /**
     * Move a claim on, and note what was found.
     *
     * @throws StorefrontException when the move would go backwards
     */
    public function move(WarrantyClaim $claim, string $status, ?string $notes): WarrantyClaim
    {
        if (! in_array($status, $claim->nextStatuses(), true)) {
            $to = WarrantyClaim::LABELS[$status] ?? $status;

            throw new StorefrontException(
                $claim->isFinal()
                    ? "This claim is {$claim->status_label} and cannot be changed."
                    : "A claim cannot go back from {$claim->status_label} to {$to}.",
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $moved = $claim->status !== $status;
        $claim->update(['status' => $status, 'diagnostic_notes' => $notes]);

        if ($moved && $status === 'ready_for_pickup') {
            $this->text('warranty_ready', $claim);
        } elseif ($moved && $status === 'rejected') {
            $this->text('warranty_rejected', $claim);
        }

        return $claim;
    }

    /**
     * Hand the customer a new unit in place of the faulty one.
     *
     * The new unit leaves the shelf and becomes the customer's, keeping the
     * warranty the old one had — a replacement continues the cover, it does
     * not start it again. The faulty one is marked faulty. What the new unit
     * cost the shop shows under Stock lost, which is what a replacement is.
     *
     * @throws StorefrontException
     */
    public function replace(WarrantyClaim $claim, int $newSerialId, ?int $userId): WarrantyClaim
    {
        return DB::transaction(function () use ($claim, $newSerialId, $userId) {
            $claim = WarrantyClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $old = $claim->unit;

            $refuse = fn (string $message) => throw new StorefrontException($message, 422, ApiCode::VALIDATION_ERROR);

            if ($claim->isFinal()) {
                $refuse("This claim is {$claim->status_label}; a replacement can no longer be given.");
            }
            if ($claim->replacement_serial_id) {
                $refuse('This claim has already been given a replacement.');
            }
            if (! $old || $old->status !== ProductSerial::SOLD) {
                $refuse('Only a unit the shop sold can be replaced here.');
            }

            $new = ProductSerial::whereKey($newSerialId)->lockForUpdate()->first();

            if (! $new || $new->status !== ProductSerial::IN_STOCK) {
                $refuse('That unit is not on the shelf. Pick another serial.');
            }
            if ((int) $new->product_id !== (int) $old->product_id
                || (int) $new->product_variant_id !== (int) $old->product_variant_id) {
                $refuse('The replacement has to be the same product as the faulty unit.');
            }

            app(StockService::class)->record($new->product, $new->variant, -1, StockMovement::WRITE_OFF, [
                'reference' => $claim,
                'reason' => 'warranty_replacement',
                'note' => "Replacement for {$claim->claim_number} (faulty unit {$old->serial})",
                'user_id' => $userId,
                'store_id' => $new->store_id,
            ]);

            $new->forceFill([
                'status' => ProductSerial::SOLD,
                'order_id' => $old->order_id,
                'order_item_id' => $old->order_item_id,
                'sold_at' => now(),
                'warranty_until' => $old->warranty_until,
                'note' => "Replacement for {$old->serial} under {$claim->claim_number}",
            ])->save();

            $old->forceFill([
                'status' => ProductSerial::FAULTY,
                'note' => "Replaced by {$new->serial} under {$claim->claim_number}",
            ])->save();

            $wasReady = $claim->status === 'ready_for_pickup';
            $claim->update([
                'replacement_serial_id' => $new->id,
                'status' => 'ready_for_pickup',
                'diagnostic_notes' => trim(($claim->diagnostic_notes ? $claim->diagnostic_notes."\n" : '')
                    ."Replaced with a new unit, serial {$new->serial}."),
            ]);

            if (! $wasReady) {
                DB::afterCommit(fn () => $this->text('warranty_ready', $claim));
            }

            return $claim;
        });
    }

    /**
     * What the shop knows about the unit a claim is about, for staff.
     *
     * The claim is accepted whatever this says; it is there so nobody repairs
     * a laptop for free that the shop never sold, or whose cover ended.
     *
     * @return array{tone:string, label:string, order_number:string|null}
     */
    public function check(WarrantyClaim $claim): array
    {
        $unit = $claim->unit;
        $order = $unit?->order?->order_number;

        if (! $unit) {
            return ['tone' => 'warn', 'label' => 'Not a unit we sold', 'order_number' => null];
        }

        $sold = $unit->sold_at?->format('d M Y');

        return match (true) {
            $claim->replacement_serial_id !== null => ['tone' => 'ok', 'label' => 'Replaced', 'order_number' => $order],
            $unit->status === ProductSerial::IN_STOCK => ['tone' => 'warn', 'label' => 'Still on our shelf — never sold', 'order_number' => null],
            $unit->status === ProductSerial::FAULTY => ['tone' => 'warn', 'label' => 'Marked faulty already', 'order_number' => $order],
            $unit->warranty_until === null => ['tone' => 'warn', 'label' => "Sold by us on {$sold} · no warranty period recorded", 'order_number' => $order],
            $unit->under_warranty === false => ['tone' => 'bad', 'label' => 'Warranty ended '.$unit->warranty_until->format('d M Y'), 'order_number' => $order],
            default => ['tone' => 'ok', 'label' => "Sold by us on {$sold} · under warranty until ".$unit->warranty_until->format('d M Y'), 'order_number' => $order],
        };
    }

    /**
     * Units on the shelf that could replace this claim's, for the picker.
     *
     * @return list<array{value:int, label:string}>
     */
    public function replacementsFor(WarrantyClaim $claim): array
    {
        $unit = $claim->unit;

        if (! $unit || $unit->status !== ProductSerial::SOLD || $claim->isFinal() || $claim->replacement_serial_id) {
            return [];
        }

        return ProductSerial::available()
            ->with('store:id,name')
            ->where('product_id', $unit->product_id)
            ->where('product_variant_id', $unit->product_variant_id)
            ->orderBy('serial')
            ->limit(200)
            ->get()
            ->map(fn (ProductSerial $s) => [
                'value' => $s->id,
                'label' => $s->serial.($s->store ? " — {$s->store->name}" : ''),
            ])
            ->all();
    }

    /** Text the customer, never letting a gateway failure undo the claim. */
    private function text(string $event, WarrantyClaim $claim): void
    {
        try {
            app(SmsService::class)->sendEvent(
                $event,
                $claim->customer_phone,
                SmsTemplates::warranty($event, $claim, BrandDetails::name())
            );
        } catch (\Throwable $e) {
            Log::warning("Could not send the {$event} SMS for {$claim->claim_number}: {$e->getMessage()}");
        }
    }

    /**
     * Sequentially-safe RMA reference. Falls back to a wider range if the small
     * space is contended, rather than looping forever.
     */
    private function claimNumber(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = 'RMA-'.random_int(100000, 999999);

            if (! WarrantyClaim::where('claim_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'RMA-'.now()->format('ymdHis').random_int(10, 99);
    }
}
