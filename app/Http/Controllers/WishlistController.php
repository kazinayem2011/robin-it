<?php

namespace App\Http\Controllers;

use App\Models\Wishlist;
use App\Services\ProductService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $wishlists = Wishlist::where('user_id', $request->user()->id)
            ->with(['product.images'])
            ->get();

        return $this->successResponse($wishlists, 'Wishlist fetched successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $wishlist = Wishlist::firstOrCreate([
            'user_id' => $request->user()->id,
            'product_id' => $request->product_id,
        ]);

        return $this->successResponse($wishlist, 'Added to wishlist');
    }

    public function destroy(Request $request, $productId): JsonResponse
    {
        Wishlist::where('user_id', $request->user()->id)
            ->where('product_id', $productId)
            ->delete();

        return $this->successResponse(null, 'Removed from wishlist');
    }

    /**
     * What else to show somebody looking at their saved list.
     *
     * Built from what they have saved, because a wishlist is a statement of
     * taste — and falling back to what is popular when it is empty, since an
     * empty wishlist is otherwise a page with nothing on it at all.
     */
    public function suggestions(Request $request, ProductService $products): JsonResponse
    {
        $saved = Wishlist::where('user_id', $request->user()->id)
            ->pluck('product_id')
            ->all();

        $suggestions = $products->similarToCart($saved);

        if ($suggestions->isEmpty()) {
            $suggestions = $products->getFeaturedProducts('all', 4);
        }

        return $this->successResponse($suggestions->values(), 'Suggestions fetched successfully');
    }
}
