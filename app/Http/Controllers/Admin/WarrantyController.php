<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WarrantyStatusRequest;
use App\Models\ProductSerial;
use App\Models\WarrantyClaim;
use App\Services\WarrantyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * RMA & warranty claims manager.
 */
class WarrantyController extends Controller
{
    public function __construct(private WarrantyService $warranty) {}

    public function index(): Response
    {
        $claims = WarrantyClaim::with(['unit.order:id,order_number', 'replacement:id,serial'])
            ->latest()
            ->get()
            ->map(fn (WarrantyClaim $claim) => $claim->toArray() + [
                // What the shop knows about the unit, so staff can see at a
                // glance whether the claim is one to honour.
                'check' => $this->warranty->check($claim),
                'next_statuses' => $claim->nextStatuses(),
                'can_replace' => $claim->unit?->status === ProductSerial::SOLD
                    && ! $claim->isFinal()
                    && ! $claim->replacement_serial_id,
                'replacement_options' => $this->warranty->replacementsFor($claim),
                'replacement_serial' => $claim->replacement?->serial,
            ]);

        return Inertia::render('Admin/Warranty', [
            'claims' => $claims,
            'statusLabels' => WarrantyClaim::LABELS,
        ]);
    }

    /**
     * Move a claim on and record what was found.
     */
    public function updateStatus(WarrantyStatusRequest $request, int $id): JsonResponse
    {
        $claim = $this->warranty->move(
            WarrantyClaim::findOrFail($id),
            $request->validated('status'),
            $request->validated('diagnostic_notes'),
        );

        return $this->successResponse($claim, "{$claim->claim_number} is now {$claim->status_label}.");
    }

    /**
     * Give the customer a new unit in place of the faulty one.
     */
    public function replace(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'serial_id' => 'required|integer|exists:product_serials,id',
        ], [
            'serial_id.required' => 'Pick the serial of the unit going to the customer.',
        ]);

        $claim = $this->warranty->replace(WarrantyClaim::findOrFail($id), (int) $validated['serial_id'], $request->user()?->id);

        return $this->successResponse(
            $claim,
            "{$claim->claim_number}: replacement recorded. It is ready for pickup."
        );
    }
}
