<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SearchTerm;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customers directory.
 */
class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->input('search', '');

        $query = User::where('role', User::ROLE_CUSTOMER)
            ->withCount('orders')
            ->withSum('orders', 'total')
            ->latest();

        if (! empty($search)) {
            $term = SearchTerm::contains($search);

            $query->where(function ($q) use ($term) {
                $q->where('name', 'LIKE', $term)
                    ->orWhere('phone', 'LIKE', $term)
                    ->orWhere('email', 'LIKE', $term);
            });
        }

        return Inertia::render('Admin/Customers', [
            'customers' => $query->paginate(20)->withQueryString(),
            'search' => $search,
        ]);
    }
}
