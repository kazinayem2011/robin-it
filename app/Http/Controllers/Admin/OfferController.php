<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OfferRequest;
use App\Models\Offer;
use App\Support\SlugFactory;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The campaigns the shop runs — "buy a desktop this month, get a gift".
 *
 * Not the discounted listing, which is worked out from product prices and
 * needs no manager. See the Offer model.
 */
class OfferController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Offers', [
            'offers' => Offer::orderBy('sort_order')->orderByDesc('id')->get(),
        ]);
    }

    public function store(OfferRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['slug'] = SlugFactory::unique(Offer::class, $validated['title']);
        // `boolean` rules leave the key absent when the field isn't posted.
        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);

        return $this->successResponse(
            Offer::create($validated),
            'Offer created successfully.',
            201
        );
    }

    public function update(OfferRequest $request, int $id): JsonResponse
    {
        $offer = Offer::findOrFail($id);
        $validated = $request->validated();

        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);

        /*
         * The slug follows a renamed title, but only while nobody can have the
         * old address yet — once an offer is live its link is in emails and on
         * posters, and moving it breaks them.
         */
        if ($offer->title !== $validated['title'] && ! $offer->is_active) {
            $validated['slug'] = SlugFactory::unique(Offer::class, $validated['title'], $offer->id);
        }

        $offer->update($validated);

        return $this->successResponse($offer->fresh(), 'Offer updated successfully.');
    }

    public function destroy(int $id): JsonResponse
    {
        Offer::findOrFail($id)->delete();

        return $this->successResponse([], 'Offer removed.');
    }
}
