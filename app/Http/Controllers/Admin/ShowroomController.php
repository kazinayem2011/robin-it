<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShowroomRequest;
use App\Models\ProductStock;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Showrooms & branch outlets manager.
 */
class ShowroomController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Stores', [
            // In the order orders pick from: the default first, then the list.
            'stores' => Store::orderByDesc('fulfils_online')->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(ShowroomRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // sort_order defaults to 0 in the schema and the form does not collect
        // it, so a new branch used to land above the flagship showroom. Append
        // to the end unless a position was given.
        $validated['sort_order'] = $validated['sort_order'] ?? ((int) Store::max('sort_order') + 1);

        $validated = $this->settleStockFlags($validated);

        $store = DB::transaction(function () use ($validated) {
            $store = Store::create($validated);
            $this->keepOneDefault($store);

            return $store;
        });

        return $this->successResponse($store, 'Branch added.', 201);
    }

    public function update(ShowroomRequest $request, int $id): JsonResponse
    {
        $store = Store::findOrFail($id);
        $validated = $request->validated();

        // Omitting the field means "leave the position as it is".
        if (! isset($validated['sort_order'])) {
            unset($validated['sort_order']);
        }

        $validated = $this->settleStockFlags($validated);

        /*
         * Stock cannot be hidden by a switch. A branch closed while units sit
         * on its shelf would drop them out of every order, count and transfer
         * without them going anywhere.
         */
        $wouldHide = $store->is_active && array_key_exists('is_active', $validated) && ! $validated['is_active'];

        if ($wouldHide && ($units = (int) ProductStock::where('store_id', $store->id)->where('quantity', '>', 0)->sum('quantity')) > 0) {
            return $this->errorResponse(
                "{$store->name} still has {$units} units in stock. Move them to another branch first.",
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        DB::transaction(function () use ($store, $validated) {
            $store->update($validated);
            $this->keepOneDefault($store);
        });

        return $this->successResponse($store->fresh(), 'Branch updated successfully.');
    }

    /**
     * The primary branch for online sales is open and holds stock: online
     * orders take from it first. Every branch holds stock; the switch for it
     * is not offered, as the shop has no branch without.
     */
    private function settleStockFlags(array $validated): array
    {
        if (! empty($validated['fulfils_online'])) {
            $validated['is_active'] = true;
        }

        if (array_key_exists('is_active', $validated) && ! $validated['is_active']) {
            $validated['fulfils_online'] = false;
        }

        return $validated;
    }

    /** One default at a time: choosing this one clears the rest. */
    private function keepOneDefault(Store $store): void
    {
        if ($store->fulfils_online) {
            Store::whereKeyNot($store->id)->where('fulfils_online', true)->update(['fulfils_online' => false]);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $store = Store::findOrFail($id);

        // Units on its shelf would vanish with it.
        $units = (int) ProductStock::where('store_id', $store->id)->where('quantity', '>', 0)->sum('quantity');

        if ($units > 0) {
            return $this->errorResponse(
                "{$store->name} still has {$units} units in stock. Move them to another branch before removing it.",
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $store->delete();

        return $this->successResponse([], 'Branch deleted.');
    }
}
