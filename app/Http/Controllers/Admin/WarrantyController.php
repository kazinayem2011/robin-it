<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WarrantyStatusRequest;
use App\Models\WarrantyClaim;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * RMA & warranty claims manager.
 */
class WarrantyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Warranty', [
            'claims' => WarrantyClaim::latest()->get(),
        ]);
    }

    /**
     * Update RMA status & diagnostic notes.
     */
    public function updateStatus(WarrantyStatusRequest $request, int $id): JsonResponse
    {
        $claim = WarrantyClaim::findOrFail($id);

        $claim->update($request->validated());

        return $this->successResponse($claim, 'Warranty RMA claim updated.');
    }
}
