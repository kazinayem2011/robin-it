<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    /**
     * Suspend a customer, or let them back in.
     *
     * Staff have had this since roles were introduced and customers never did,
     * so the only way to stop somebody ordering — a fraudulent account, a
     * chargeback, somebody abusing cash on delivery by refusing every parcel —
     * was to delete them, which takes their order history with it.
     *
     * Suspending keeps everything and closes the door: the account stays, the
     * orders stay, and the person cannot sign in.
     */
    public function setActive(Request $request, int $id): JsonResponse
    {
        $customer = User::where('role', User::ROLE_CUSTOMER)->findOrFail($id);

        $active = $request->validate([
            'is_active' => 'required|boolean',
        ])['is_active'];

        $customer->forceFill(['is_active' => $active])->save();

        /*
         * A suspended account must not keep an open session. Without this the
         * customer stays signed in on whatever device they are already using
         * until the session expires, which for the abuse cases this exists for
         * is the entire window that matters.
         */
        if (! $active) {
            DB::table('sessions')->where('user_id', $customer->id)->delete();
            $customer->forceFill(['remember_token' => Str::random(60)])->save();
        }

        return $this->successResponse(
            ['is_active' => $customer->is_active],
            $active
                ? "{$customer->name} can sign in again."
                : "{$customer->name} has been suspended and signed out."
        );
    }
}
