<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiCode;
use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockNotification;
use App\Services\ShopNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * "Tell me when this is back."
 *
 * A shopper who finds something sold out currently just leaves. This is the
 * only place they can say they still want it.
 */
class StockNotificationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'contact' => 'nullable|string|max:255',
            'email' => 'nullable|string|max:255',
        ]);

        /*
         * An email address or a mobile number, in the one box — as signing in
         * takes either. The list was email only, so a customer whose account
         * is a mobile number, and a guest with no address, could not join it.
         * `email` is still read, for anything posting the old field.
         */
        $field = $request->filled('contact') ? 'contact' : 'email';
        $raw = trim((string) $request->input($field, ''));
        $email = null;
        $phone = null;

        if ($raw === '') {
            throw ValidationException::withMessages([
                $field => 'Enter an email address or a mobile number so we can tell you.',
            ]);
        }

        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            $email = strtolower($raw);
        } elseif (PhoneHelper::isValidBdPhone($raw)) {
            $phone = PhoneHelper::normalizeBdPhone($raw);
        } else {
            throw ValidationException::withMessages([
                $field => 'Enter an email address, or an 11-digit mobile number such as 01711223344.',
            ]);
        }

        $product = Product::find($validated['product_id']);
        $variant = null;

        if (! empty($validated['product_variant_id'])) {
            $variant = ProductVariant::where('product_id', $product->id)
                ->find($validated['product_variant_id']);

            if (! $variant) {
                return $this->errorResponse(
                    'That option does not belong to this product.',
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }
        }

        if ($product->has_variants && ! $variant) {
            return $this->errorResponse(
                'Choose which option you are waiting for.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        // Nothing to wait for if it is already there.
        $available = (int) ($variant?->stock_quantity ?? $product->stock_quantity);

        if ($available > 0) {
            return $this->errorResponse(
                'Good news — this is in stock right now.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        // updateOrCreate rather than create: asking twice should be reassuring,
        // not a duplicate-key error. Keyed on whichever of the two was given.
        $notification = StockNotification::updateOrCreate(
            [
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                ...($email !== null ? ['email' => $email] : ['phone' => $phone]),
            ],
            [
                'user_id' => Auth::id(),
                // Clearing this re-arms a request from the last time it sold
                // out, so someone is not silently left off the list.
                'notified_at' => null,
            ]
        );

        $waiting = StockNotification::forUnit($product->id, $variant?->id)
            ->pending()->count();

        /*
         * Tell the shop, once per request: new, or re-armed after they were
         * told last time. Pressing it twice while already waiting is the
         * customer reassuring themselves, not a second person in the queue.
         */
        if ($notification->wasRecentlyCreated || $notification->wasChanged('notified_at')) {
            app(ShopNotifier::class)->stockRequested($product, $variant, $waiting);
        }

        return $this->successResponse(
            [
                'waiting' => $waiting,
            ],
            $email !== null
                ? "We'll email {$email} as soon as it's back."
                : "We'll text {$phone} as soon as it's back.",
            201
        );
    }

    /** How many people are waiting, so the page can say so. */
    public function count(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'product_variant_id' => 'nullable|integer',
        ]);

        return $this->successResponse([
            'waiting' => StockNotification::forUnit(
                (int) $validated['product_id'],
                isset($validated['product_variant_id']) ? (int) $validated['product_variant_id'] : null
            )->pending()->count(),
        ]);
    }
}
