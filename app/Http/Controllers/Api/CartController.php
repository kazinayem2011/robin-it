<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Http\Controllers\Controller;
use App\Services\CartService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    public function __construct(
        protected CartService $cartService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $cart = $this->cartService->getCartWithItems(
            Auth::id(),
            $request->session()->getId()
        );

        $totals = $this->cartService->calculateTotals($cart);

        return $this->successResponse([
            'id' => $cart->id,
            'items' => $cart->items,
            'totals' => $totals,
            // Anything that sold out or was delisted while sitting in the cart,
            // so the cart page can warn before the customer reaches checkout.
            'issues' => $cart->exists ? $this->cartService->findUnavailableItems($cart) : [],
        ], 'Cart fetched successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'quantity' => 'required|integer|min:1|max:'.CartService::MAX_QUANTITY_PER_ITEM,
        ], [
            'product_id.exists' => 'That product is no longer available.',
            'product_variant_id.exists' => 'That option is no longer available.',
            'quantity.max' => 'You can order up to '.CartService::MAX_QUANTITY_PER_ITEM.' units of a single item. Contact us for bulk orders.',
        ]);

        try {
            $cart = $this->cartService->getOrCreateCart(
                Auth::id(),
                $request->session()->getId()
            );

            $cartItem = $this->cartService->addItem(
                $cart,
                (int) $validated['product_id'],
                (int) $validated['quantity'],
                isset($validated['product_variant_id']) ? (int) $validated['product_variant_id'] : null
            );

            return $this->successResponse(
                $cartItem->load('product', 'variant'),
                'Added to your cart.'
            );
        } catch (StorefrontException $e) {
            return $this->storefrontErrorResponse($e);
        }
    }

    public function update(Request $request, int|string $itemId): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:'.CartService::MAX_QUANTITY_PER_ITEM,
        ], [
            'quantity.max' => 'You can order up to '.CartService::MAX_QUANTITY_PER_ITEM.' units of a single item. Contact us for bulk orders.',
        ]);

        $cart = $this->cartService->findCart(
            Auth::id(),
            $request->session()->getId()
        );

        if (! $cart) {
            return $this->errorResponse('Your cart is empty.', 404, ApiCode::CART_EMPTY);
        }

        try {
            $cartItem = $this->cartService->updateItemQuantity(
                $cart,
                (int) $itemId,
                (int) $validated['quantity']
            );

            return $this->successResponse($cartItem->load('product', 'variant'), 'Cart updated.');
        } catch (StorefrontException $e) {
            return $this->storefrontErrorResponse($e);
        } catch (ModelNotFoundException) {
            return $this->errorResponse('That item is no longer in your cart.', 404, ApiCode::NOT_FOUND);
        }
    }

    public function destroy(Request $request, int|string $itemId): JsonResponse
    {
        $cart = $this->cartService->findCart(
            Auth::id(),
            $request->session()->getId()
        );

        if ($cart) {
            $this->cartService->removeItem($cart, (int) $itemId);
        }

        // Removing something that is already gone is the outcome the customer wanted.
        return $this->successResponse(null, 'Removed from your cart.');
    }
}
