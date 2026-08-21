<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\WarrantyClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarrantyController extends Controller
{
    /** Standard cover on genuine hardware sold through the store. */
    private const WARRANTY_MONTHS = 36;

    /**
     * Check Warranty Status by Serial Number, Invoice Number, or RMA Claim ID.
     *
     * Deliberately reports only warranty and RMA information. It used to also return
     * order status and item counts for any guessed order number, which handed order
     * details to anyone without authentication.
     */
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|min:3|max:100',
        ], [
            'query.required' => 'Please enter your Serial Number, Invoice Number, or RMA Claim ID.',
            'query.min' => 'Please enter at least 3 characters of your Serial, Invoice or RMA number.',
        ]);

        $query = strtoupper(trim($validated['query']));

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

        $purchaseDate = $claim?->purchase_date ?? $order?->created_at;

        // Only state a warranty window when there is a real purchase date behind it.
        if ($purchaseDate) {
            $expiryDate = $purchaseDate->copy()->addMonths(self::WARRANTY_MONTHS);
            $isUnderWarranty = now()->lessThanOrEqualTo($expiryDate);

            $warranty = [
                'warranty_known' => true,
                'is_under_warranty' => $isUnderWarranty,
                'warranty_period' => self::WARRANTY_MONTHS.' Months Official Genuine Brand Warranty',
                'purchase_date' => $purchaseDate->format('d M Y'),
                'warranty_expiry' => $expiryDate->format('d M Y'),
                'days_remaining' => $isUnderWarranty ? (int) now()->diffInDays($expiryDate, false) : 0,
            ];
        } else {
            $warranty = [
                'warranty_known' => false,
                'is_under_warranty' => false,
                'warranty_period' => 'Purchase date not on record — our service desk can confirm your cover.',
                'purchase_date' => null,
                'warranty_expiry' => null,
                'days_remaining' => 0,
            ];
        }

        return $this->successResponse(array_merge([
            'query' => $query,
            'existing_claim' => $claim ? [
                'claim_number' => $claim->claim_number,
                'product_name' => $claim->product_name,
                'status' => $claim->status,
                'issue_type' => $claim->issue_type,
                'diagnostic_notes' => $claim->diagnostic_notes,
                'updated_at' => $claim->updated_at->format('d M Y, h:i A'),
            ] : null,
        ], $warranty), 'Warranty status retrieved successfully.');
    }

    /**
     * Submit a New Warranty / RMA Claim Request.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => 'required|string|max:100',
            'customer_phone' => ['required', 'string', 'max:20', 'regex:/^(?:\+?88|88)?01[3-9]\d{8}$/'],
            'customer_email' => 'nullable|email|max:150',
            'product_name' => 'required|string|max:150',
            'serial_number' => 'required|string|max:100',
            'invoice_number' => 'nullable|string|max:100',
            'purchase_date' => 'nullable|date|before_or_equal:today',
            'issue_type' => 'required|string|max:100',
            'issue_description' => 'required|string|min:10|max:2000',
            'dropoff_branch' => 'nullable|string|max:100',
        ], [
            'customer_phone.regex' => 'Please enter a valid 11-digit Bangladeshi mobile number so we can update you on the repair.',
            'issue_description.min' => 'Please describe the fault in at least 10 characters so our technicians can prepare.',
            'purchase_date.before_or_equal' => 'Purchase date cannot be in the future.',
        ]);

        $claim = new WarrantyClaim;
        $claim->fill($validated);
        $claim->claim_number = $this->generateClaimNumber();
        $claim->user_id = auth('sanctum')->id() ?? auth()->id();
        $claim->status = 'received';
        $claim->diagnostic_notes = 'Claim logged. Hardware awaiting intake diagnosis at Robin IT Central Service Lab.';
        $claim->save();

        return $this->successResponse([
            'claim_number' => $claim->claim_number,
            'product_name' => $claim->product_name,
            'status' => $claim->status,
            'dropoff_branch' => $claim->dropoff_branch ?: 'Robin IT Central Service Center, Multiplan Center, Dhaka',
            'created_at' => $claim->created_at->format('d M Y, h:i A'),
        ], "Warranty claim #{$claim->claim_number} logged successfully! Our service technicians will inspect your unit.", 201);
    }

    /**
     * Sequentially-safe RMA reference. Falls back to a wider range if the small
     * space is contended, rather than looping forever.
     */
    private function generateClaimNumber(): string
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
