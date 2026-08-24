<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\BrandDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The printable invoice.
 *
 * Rendered as a plain server-side page rather than inside the app shell: it has
 * to print cleanly, and the browser's own "Save as PDF" is the most reliable
 * way to get a file without shipping a PDF library. A cash-on-delivery order
 * needs paperwork going out with the rider, and the account and admin screens
 * have both been promising "invoices" without producing one.
 */
class InvoiceController extends Controller
{
    public function show(Request $request, int $orderId)
    {
        $order = Order::with(['items.product.images', 'items.variant', 'user'])->find($orderId);

        abort_if(! $order, 404, 'That order could not be found.');
        abort_unless($this->mayView($request, $order), 403, 'That invoice is not yours to view.');

        return view('invoices.order', [
            'order' => $order,
            'brand' => BrandDetails::all(),
            'logo' => BrandDetails::logoWebPath(),
            // Printed straight away when arrived at with ?print=1, so the
            // button on the order screen is one click rather than two.
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    /**
     * Who may see an invoice.
     *
     * Its own method because getting this wrong exposes a customer's name,
     * address and phone number to anyone who can guess an order id.
     */
    private function mayView(Request $request, Order $order): bool
    {
        $user = Auth::user();

        if ($user && $user->role === 'admin') {
            return true;
        }

        if ($user && $order->user_id === $user->id) {
            return true;
        }

        // A guest checkout is tied to the session that placed it, so the person
        // who just ordered can still print their own receipt.
        return $order->user_id === null
            && $order->session_id !== null
            && $order->session_id === $request->session()->getId();
    }
}
