<?php

namespace App\Http\Controllers\Customer;

use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Order;
use App\Models\Wishlist;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the Customer Account Dashboard.
     */
    /**
     * What every account page needs: who is signed in, the counts beside the
     * sidebar links, and the points shown in the header.
     *
     * @return array<string, mixed>
     */
    private function shell($user): array
    {
        $spend = (float) Order::where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->sum('total');

        return [
            'user' => $user,
            'navCounts' => [
                'orders' => Order::where('user_id', $user->id)->count(),
                'wishlist' => Wishlist::where('user_id', $user->id)->count(),
            ],
            'techPoints' => (int) floor($spend / 100),
        ];
    }

    /**
     * Account overview.
     *
     * Only the few most recent orders are loaded. This used to send every order
     * with all its items, every address and every wishlist product on every
     * visit, whichever section the customer was actually looking at.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        $counts = Order::where('user_id', $user->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $spend = (float) Order::where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')
            ->sum('total');

        $stats = [
            'total_orders' => (int) $counts->sum(),
            'pending_orders' => (int) $counts->only(['pending', 'processing', 'shipped'])->sum(),
            'completed_orders' => (int) ($counts['delivered'] ?? 0),
            'wishlist_count' => Wishlist::where('user_id', $user->id)->count(),
            'total_spent' => $spend,
            'tech_points' => (int) floor($spend / 100),
        ];

        return Inertia::render('Dashboard/Index', array_merge($this->shell($user), [
            'recentOrders' => Order::where('user_id', $user->id)
                ->with(['items.product.images'])
                ->latest()
                ->take(3)
                ->get(),
            'stats' => $stats,
        ]));
    }

    public function orders(Request $request): Response
    {
        $user = Auth::user();

        return Inertia::render('Dashboard/Orders', array_merge($this->shell($user), [
            'orders' => Order::where('user_id', $user->id)
                ->with(['items.product.images'])
                ->latest()
                ->get(),
        ]));
    }

    public function wishlist(Request $request): Response
    {
        $user = Auth::user();

        return Inertia::render('Dashboard/Wishlist', array_merge($this->shell($user), [
            'wishlistItems' => Wishlist::where('user_id', $user->id)
                ->with(['product.brand', 'product.images'])
                ->get(),
        ]));
    }

    public function addresses(Request $request): Response
    {
        $user = Auth::user();

        return Inertia::render('Dashboard/Addresses', array_merge($this->shell($user), [
            'addresses' => Address::where('user_id', $user->id)->get(),
        ]));
    }

    public function profile(Request $request): Response
    {
        return Inertia::render('Dashboard/Profile', $this->shell(Auth::user()));
    }

    /**
     * Update customer profile info.
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'phone' => [
                'required',
                'string',
                'regex:/^(?:\+?88|88)?01[3-9]\d{8}$/',
                'unique:users,phone,'.$user->id,
            ],
        ], [
            'phone.regex' => 'Please enter a valid 11-digit Bangladeshi mobile number.',
            'phone.unique' => 'This phone number is already registered by another account.',
        ]);

        $validated['phone'] = PhoneHelper::normalizeBdPhone($validated['phone']);

        $user->update($validated);

        return back()->with('success', 'Profile updated successfully.');
    }

    /**
     * Store or update a delivery address.
     */
    public function saveAddress(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'id' => 'nullable|integer',
            'division' => 'required|string|max:100',
            'district' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'address' => 'required|string|max:500',
            'is_default' => 'nullable|boolean',
        ], [
            'address.required' => 'Please enter your full street address, including house and road number.',
            'division.required' => 'Please choose your division.',
            'district.required' => 'Please choose your district.',
            'city.required' => 'Please enter your city or thana.',
        ]);

        $isFirstAddress = Address::where('user_id', $user->id)->count() === 0;
        $makeDefault = ! empty($validated['is_default']) || $isFirstAddress;

        DB::transaction(function () use ($validated, $user, $makeDefault) {
            $payload = [
                'division' => $validated['division'],
                'district' => $validated['district'],
                'city' => $validated['city'],
                'address' => $validated['address'],
                'is_default' => $makeDefault,
            ];

            if (! empty($validated['id'])) {
                // Scoped to the signed-in user — an id belonging to someone else 404s.
                $address = Address::where('id', $validated['id'])
                    ->where('user_id', $user->id)
                    ->firstOrFail();

                $address->update($payload);
                $keepId = $address->id;
            } else {
                $keepId = Address::create($payload + ['user_id' => $user->id])->id;
            }

            if ($makeDefault) {
                Address::where('user_id', $user->id)
                    ->whereKeyNot($keepId)
                    ->update(['is_default' => false]);
            }
        });

        return back()->with('success', 'Delivery address saved successfully.');
    }

    /**
     * Delete an address.
     */
    public function deleteAddress($id)
    {
        $user = Auth::user();

        $address = Address::where('id', $id)->where('user_id', $user->id)->first();

        if (! $address) {
            return back()->with('error', 'That address has already been removed.');
        }

        $wasDefault = $address->is_default;
        $address->delete();

        // Never leave the customer without a default delivery address.
        if ($wasDefault) {
            $next = Address::where('user_id', $user->id)->oldest()->first();
            $next?->update(['is_default' => true]);
        }

        return back()->with('success', 'Address removed successfully.');
    }

    /**
     * Let a customer call off an order that has not shipped yet.
     *
     * Cancelling routes through OrderService, which returns the reserved stock
     * to the shelf so the units become sellable again.
     */
    public function cancelOrder(Request $request, OrderService $orderService, $id)
    {
        $user = Auth::user();

        $order = Order::where('id', $id)->where('user_id', $user->id)->first();

        if (! $order) {
            return back()->with('error', 'We could not find that order on your account.');
        }

        if ($order->isCancelled()) {
            return back()->with('error', 'That order is already cancelled.');
        }

        if (! $order->isCancellableByCustomer()) {
            return back()->with(
                'error',
                "Order #{$order->order_number} has already been dispatched and can no longer be cancelled online. "
                .'Please contact support and our team will help you with a return.'
            );
        }

        $orderService->updateOrderStatus($order, 'cancelled');

        return back()->with('success', "Order #{$order->order_number} has been cancelled.");
    }

    /**
     * Update customer password.
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('success', 'Password changed successfully.');
    }
}
