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

        if ($wouldHide && ($refusal = $this->refuseWhileHolding($store, 'closing it'))) {
            return $refusal;
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

        if ($refusal = $this->refuseWhileHolding($store, 'removing it')) {
            return $refusal;
        }

        $store->delete();

        return $this->successResponse([], 'Branch deleted.');
    }

    /**
     * A branch that holds stock, or owes it, cannot go.
     *
     * Units on its shelf would vanish with it. Units it owes — sold to
     * customers who ordered past stock, waiting for a delivery there — were
     * missed: only stock above zero was checked, so a branch at -2 could be
     * closed with two customers still waiting on it.
     */
    private function refuseWhileHolding(Store $store, string $doing): ?JsonResponse
    {
        $rows = ProductStock::where('store_id', $store->id)->where('quantity', '!=', 0)->get(['quantity']);
        $held = (int) $rows->where('quantity', '>', 0)->sum('quantity');
        $owed = (int) -$rows->where('quantity', '<', 0)->sum('quantity');

        if ($held === 0 && $owed === 0) {
            return null;
        }

        $what = collect([
            $held ? "has {$held} units in stock" : null,
            $owed ? "owes {$owed} units to customers waiting for a delivery" : null,
        ])->filter()->implode(' and ');

        $fix = collect([
            $held ? 'move the stock to another branch' : null,
            $owed ? 'receive the delivery or ship those orders from another branch' : null,
        ])->filter()->implode(', and ');

        return $this->errorResponse(
            "{$store->name} still {$what}. Before {$doing}, {$fix}.",
            422,
            ApiCode::VALIDATION_ERROR
        );
    }
}
