<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExpenseRequest;
use App\Models\Expense;
use App\Models\Supplier;
use App\Support\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Running costs: rent, wages, the courier's bill, packaging, advertising.
 *
 * Not stock. Units bought are inventory until they sell, and they reach the
 * accounts as cost of goods sold on the order that sells them — entering a
 * delivery here as well would count the same money twice.
 */
class ExpenseController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'category' => (string) $request->query('category', 'all'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];

        $query = Expense::query()
            ->with(['supplier:id,name', 'recordedBy:id,name'])
            ->between($filters['from'], $filters['to'])
            ->when(
                $filters['category'] !== 'all' && $filters['category'] !== '',
                fn ($q) => $q->where('category', $filters['category'])
            )
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $term = SearchTerm::contains($filters['search']);

                $q->where(function ($inner) use ($term) {
                    $inner->where('description', 'LIKE', $term)
                        ->orWhere('reference', 'LIKE', $term)
                        ->orWhere('note', 'LIKE', $term);
                });
            })
            ->orderByDesc('incurred_on')
            ->orderByDesc('id');

        return Inertia::render('Admin/Expenses', [
            'expenses' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'filters' => $filters,
            'categories' => collect(Expense::CATEGORIES)
                ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])
                ->values(),
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name']),
            // What the current filter adds up to, so the figure on screen
            // matches the rows on screen.
            'total' => round((float) (clone $query)->sum('amount'), 2),
        ]);
    }

    public function store(ExpenseRequest $request): JsonResponse
    {
        $expense = Expense::create($request->validated() + ['user_id' => $request->user()?->id]);

        return $this->successResponse(
            $expense->load('supplier:id,name'),
            'Expense recorded.',
            201
        );
    }

    public function update(ExpenseRequest $request, int $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        $expense->update($request->validated());

        return $this->successResponse($expense->load('supplier:id,name'), 'Expense updated.');
    }

    public function destroy(int $id): JsonResponse
    {
        Expense::findOrFail($id)->delete();

        return $this->successResponse([], 'Expense removed.');
    }
}
