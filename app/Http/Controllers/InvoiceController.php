<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\BrandDetails;
use App\Support\VatRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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

        // Who may see an invoice now lives in OrderPolicy, with the rest of the
        // rules about who owns an order. Getting this wrong exposes a
        // customer's name, address and phone number to anyone who can guess an
        // order id, so it is worth having in one place rather than restated
        // here.
        abort_unless(
            Gate::allows('print', [$order, $request]),
            403,
            'That invoice is not yours to view.'
        );

        return view('invoices.order', [
            'order' => $order,
            'brand' => BrandDetails::all(),
            'logo' => BrandDetails::logoWebPath(),
            // Read now rather than frozen: it identifies the business, not the
            // sale, and a shop that registers later wants it on reprints.
            'vatNumber' => VatRules::registrationNumber(),
            // Printed straight away when arrived at with ?print=1, so the
            // button on the order screen is one click rather than two.
            'autoPrint' => $request->boolean('print'),
        ]);
    }
}
