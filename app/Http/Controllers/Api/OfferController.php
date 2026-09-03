<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use Illuminate\Http\JsonResponse;

/**
 * The campaigns the shop is running, for the storefront.
 *
 * Distinct from the discounted listing, which is /discounts and is worked out
 * from product prices. See the Offer model.
 */
class OfferController extends Controller
{
    /**
     * What is on, and what is coming.
     *
     * Everything, not a page of it: a shop runs ten or fifteen of these, not a
     * catalogue's worth, and the page shows them all at once.
     */
    public function index(): JsonResponse
    {
        return $this->successResponse(
            Offer::current()->get(),
            'Offers fetched successfully.'
        );
    }

    /**
     * One offer, by slug.
     *
     * An offer that has ended still answers here — a link sent to a customer
     * last week should explain what it was, and the page says it has finished.
     * Only one switched off by staff is gone.
     */
    public function show(string $slug): JsonResponse
    {
        $offer = Offer::active()->where('slug', $slug)->first();

        if (! $offer) {
            return $this->errorResponse('Offer not found.', 404, ApiCode::NOT_FOUND);
        }

        return $this->successResponse($offer, 'Offer details fetched successfully.');
    }
}
