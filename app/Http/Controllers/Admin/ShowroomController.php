<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShowroomRequest;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
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
            'stores' => Store::orderBy('city')->get(),
        ]);
    }

    public function store(ShowroomRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // sort_order defaults to 0 in the schema and the form does not collect
        // it, so a new branch used to land above the flagship showroom. Append
        // to the end unless a position was given.
        $validated['sort_order'] = $validated['sort_order'] ?? ((int) Store::max('sort_order') + 1);

        $store = Store::create($validated);

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

        $store->update($validated);

        return $this->successResponse($store, 'Branch updated successfully.');
    }

    public function destroy(int $id): JsonResponse
    {
        Store::findOrFail($id)->delete();

        return $this->successResponse([], 'Branch deleted.');
    }
}
