<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
            'email' => 'required|email|max:255',
        ], [
            'email.required' => 'Enter an email address so we can tell you.',
            'email.email' => 'That does not look like an email address.',
        ]);

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

        $email = strtolower(trim($validated['email']));

        // updateOrCreate rather than create: asking twice should be reassuring,
        // not a duplicate-key error.
        $notification = StockNotification::updateOrCreate(
            [
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'email' => $email,
            ],
            [
                'user_id' => Auth::id(),
                // Clearing this re-arms a request from the last time it sold
                // out, so someone is not silently left off the list.
                'notified_at' => null,
            ]
        );

        return $this->successResponse(
            [
                'waiting' => StockNotification::forUnit($product->id, $variant?->id)
                    ->pending()->count(),
            ],
            "We'll email {$email} as soon as it's back.",
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
