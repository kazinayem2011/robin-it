<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Suppliers.
 *
 * Their own section rather than a corner of the stock screen: who the shop buys
 * from is a standing list to be maintained, not something to be filled in
 * halfway through recording a delivery.
 */
class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $suppliers = Supplier::query()
            ->withCount('receipts')
            ->withSum('receipts as units_received', 'total_quantity')
            ->withSum('receipts as total_spend', 'total_cost')
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Suppliers', [
            'suppliers' => $suppliers,
            'filters' => ['search' => $search],
        ]);
    }

    /** Suppliers for the delivery form's dropdown. */
    public function options()
    {
        return $this->successResponse(
            Supplier::active()->orderBy('name')->get(['id', 'name', 'contact_name', 'phone', 'email']),
            'Suppliers fetched.'
        );
    }

    public function store(Request $request)
    {
        $supplier = Supplier::create($this->validated($request) + ['is_active' => true]);

        return $this->successResponse($supplier, "Supplier '{$supplier->name}' added.", 201);
    }

    public function update(Request $request, int $id)
    {
        $supplier = Supplier::findOrFail($id);

        $supplier->update($this->validated($request, $supplier->id));

        return $this->successResponse($supplier, 'Supplier updated.');
    }

    /**
     * Retire a supplier.
     *
     * Deactivated rather than deleted when deliveries reference it, so the
     * record of who supplied what does not quietly disappear.
     */
    public function destroy(int $id)
    {
        $supplier = Supplier::findOrFail($id);

        if ($supplier->receipts()->exists()) {
            $supplier->update(['is_active' => false]);

            return $this->successResponse(
                $supplier,
                "'{$supplier->name}' has past deliveries, so it has been deactivated rather than deleted."
            );
        }

        $supplier->delete();

        return $this->successResponse([], 'Supplier removed.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255|unique:suppliers,name'.($ignoreId ? ','.$ignoreId : ''),
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'note' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
        ], [
            'name.unique' => 'A supplier with that name already exists.',
        ]);
    }
}
