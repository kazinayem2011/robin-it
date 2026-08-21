<?php

namespace App\Http\Controllers;

use App\Models\Comparison;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ComparisonController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = Comparison::with(['product.images', 'product.specifications']);

        if (Auth::check()) {
            $query->where('user_id', Auth::id());
        } else {
            $query->where('session_id', $request->session()->getId());
        }

        $comparisons = $query->get();

        return $this->successResponse($comparisons, 'Comparison list fetched successfully');
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $attributes = ['product_id' => $request->product_id];
        $countQuery = Comparison::query();

        if (Auth::check()) {
            $attributes['user_id'] = Auth::id();
            $countQuery->where('user_id', Auth::id());
        } else {
            $attributes['session_id'] = $request->session()->getId();
            $countQuery->where('session_id', $request->session()->getId());
        }

        $exists = (clone $countQuery)->where('product_id', $request->product_id)->exists();
        if (! $exists && $countQuery->count() >= 4) {
            return $this->errorResponse('You can compare a maximum of 4 products at a time. Please remove an item first.', 422);
        }

        $comparison = Comparison::firstOrCreate($attributes);

        return $this->successResponse($comparison, 'Added to comparison matrix');
    }

    public function destroy(Request $request, $productId): JsonResponse
    {
        $query = Comparison::where('product_id', $productId);

        if (Auth::check()) {
            $query->where('user_id', Auth::id());
        } else {
            $query->where('session_id', $request->session()->getId());
        }

        $query->delete();

        return $this->successResponse(null, 'Removed from comparison');
    }
}
