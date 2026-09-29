<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiCode;
use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductSerial;
use App\Models\WarrantyClaim;
use App\Services\SerialService;
use App\Services\WarrantyService;
use App\Support\ShopDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarrantyController extends Controller
{
    /**
     * Check Warranty Status by Serial Number, Invoice Number, or RMA Claim ID.
     *
     * Deliberately reports only warranty and RMA information. It used to also return
     * order status and item counts for any guessed order number, which handed order
     * details to anyone without authentication.
     */
    public function check(Request $request, SerialService $serials): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|min:3|max:100',
        ], [
            'query.required' => 'Please enter your Serial Number, Invoice Number, or RMA Claim ID.',
            'query.min' => 'Please enter at least 3 characters of your Serial, Invoice or RMA number.',
        ]);

        $query = strtoupper(trim($validated['query']));

        /*
         * A serial the shop actually sold answers this properly: the real
         * product, the day it went out, and that product's own cover. Everything
         * below falls back to a flat period applied to every item in the
         * catalogue, which was the only answer available before units were
         * tracked.
         */
        if ($unit = $serials->lookup($query)) {
            // With any claim on it: a customer who filed one and came back
            // with the serial was shown the warranty and not the repair.
            $claim = WarrantyClaim::where('product_serial_id', $unit->id)
                ->orWhere('serial_number', $unit->serial)
                ->latest()
                ->first();

            return $this->successResponse(
                ['existing_claim' => $claim ? $this->claimView($claim) : null] + $this->fromSerial($unit),
                'Warranty details found.'
            );
        }

        $claim = WarrantyClaim::where('claim_number', $query)
            ->orWhere('serial_number', $query)
            ->orWhere('invoice_number', $query)
            ->latest()
            ->first();

        // An order is used only to establish a genuine purchase date — never echoed back.
        $order = Order::where('order_number', $query)->first();

        if (! $claim && ! $order) {
            return $this->errorResponse(
                "We couldn't find any record for \"{$query}\". Please check the number on your invoice or RMA slip, or contact support.",
                404,
                ApiCode::NOT_FOUND
            );
        }

        $purchaseDate = $claim?->purchase_date ?? $order?->delivered_at ?? $order?->created_at;

        /*
         * The product's own cover, not a flat 36 months for everything. An
         * order of several things answers with the longest of them; a claim
         * alone says nothing about which product, so it has none.
         */
        $months = $order
            ? $order->items()->with('product:id,warranty_months')->get()->max(fn ($i) => (int) $i->product?->warranty_months)
            : ($claim?->unit?->product?->warranty_months);

        // Only state a warranty window when there is a real purchase date and
        // a real period behind it.
        if ($purchaseDate && $months) {
            $expiryDate = $purchaseDate->copy()->addMonths($months);
            $isUnderWarranty = now()->lessThanOrEqualTo($expiryDate);

            $warranty = [
                'warranty_known' => true,
                'is_under_warranty' => $isUnderWarranty,
                'warranty_period' => "{$months} months from the date of purchase",
                'purchase_date' => $purchaseDate->format('d M Y'),
                'warranty_expiry' => $expiryDate->format('d M Y'),
                'days_remaining' => $isUnderWarranty ? (int) now()->diffInDays($expiryDate, false) : 0,
            ];
        } else {
            $warranty = [
                'warranty_known' => false,
                'is_under_warranty' => false,
                'warranty_period' => 'Not on record here — our service desk can confirm your cover.',
                'purchase_date' => null,
                'warranty_expiry' => null,
                'days_remaining' => 0,
            ];
        }

        return $this->successResponse(array_merge([
            'query' => $query,
            'existing_claim' => $claim ? $this->claimView($claim) : null,
        ], $warranty), 'Warranty status retrieved successfully.');
    }

    /**
     * Submit a New Warranty / RMA Claim Request.
     */
    public function store(Request $request, WarrantyService $warranty): JsonResponse
    {
        // The rules judge the number, not its punctuation.
        PhoneHelper::canonicalise($request, 'customer_phone');

        $validated = $request->validate([
            'customer_name' => 'required|string|max:100',
            'customer_phone' => ['required', 'string', 'max:20', PhoneHelper::RULE],
            'customer_email' => 'nullable|email|max:150',
            'product_name' => 'required|string|max:150',
            'serial_number' => 'required|string|max:100',
            'invoice_number' => 'nullable|string|max:100',
            'purchase_date' => 'nullable|date|'.ShopDate::notInFuture(),
            'issue_type' => 'required|string|max:100',
            'issue_description' => 'required|string|min:10|max:2000',
            'dropoff_branch' => 'nullable|string|max:100',
        ], [
            'customer_phone.regex' => 'Please enter a valid 11-digit Bangladeshi mobile number so we can update you on the repair.',
            'issue_description.min' => 'Please describe the fault in at least 10 characters so our technicians can prepare.',
            'purchase_date.before_or_equal' => 'Purchase date cannot be in the future.',
        ]);

        $claim = $warranty->submit($validated, auth('sanctum')->id() ?? auth()->id());

        return $this->successResponse([
            'claim_number' => $claim->claim_number,
            'product_name' => $claim->product_name,
            'status' => $claim->status,
            // Courier pickup, to match the form's default — naming a branch
            // here once meant echoing back a service centre that had been
            // renamed, and the customer would post the unit to it.
            'dropoff_branch' => $claim->dropoff_branch ?: 'Doorstep Courier Pickup (All 64 Districts)',
            'created_at' => ShopDate::show($claim->created_at, 'd M Y, h:i A'),
        ], "Warranty claim #{$claim->claim_number} logged successfully! Our service technicians will inspect your unit.", 201);
    }

    /**
     * A claim as the customer sees it.
     *
     * @return array<string, mixed>
     */
    private function claimView(WarrantyClaim $claim): array
    {
        return [
            'claim_number' => $claim->claim_number,
            'product_name' => $claim->product_name,
            'status' => $claim->status,
            'status_label' => $claim->status_label,
            'issue_type' => $claim->issue_type,
            'diagnostic_notes' => $claim->diagnostic_notes,
            'replacement_serial' => $claim->replacement?->serial,
            'updated_at' => ShopDate::show($claim->updated_at, 'd M Y, h:i A'),
        ];
    }

    /**
     * What the shop knows about one unit it sold.
     *
     * The same shape the page already renders, so nothing downstream has to
     * learn a second format — only the figures are real rather than assumed.
     *
     * @return array<string, mixed>
     */
    private function fromSerial(ProductSerial $unit): array
    {
        $months = $unit->product?->warranty_months;
        $sold = $unit->sold_at;
        $expires = $unit->warranty_until;
        $covered = $unit->under_warranty === true;

        return [
            'serial_number' => $unit->serial,
            'product_name' => $unit->variant
                ? "{$unit->product?->name} ({$unit->variant->name})"
                : $unit->product?->name,
            'warranty_known' => (bool) $expires,
            'is_under_warranty' => $covered,
            'warranty_period' => $unit->status === ProductSerial::IN_STOCK
                ? ($months
                    ? "{$months} months, starting from the day it is sold"
                    : 'No warranty period recorded for this product')
                : ($months
                    ? "{$months} months from the date of sale"
                    : 'No warranty period recorded for this product'),
            'purchase_date' => $sold?->format('d M Y'),
            'warranty_expiry' => $expires?->format('d M Y'),
            'days_remaining' => $covered ? (int) now()->diffInDays($expires->endOfDay(), false) : 0,
            /*
             * A unit still on the shelf is not a customer's to claim on. Said
             * plainly rather than reported as "expired", which would send
             * somebody arguing about a warranty that has not started.
             */
            'not_yet_sold' => $unit->status === ProductSerial::IN_STOCK,
        ];
    }
}
