<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request, string $productSlug): JsonResponse
    {
        $product = Product::where('slug', $productSlug)->firstOrFail();

        $reviews = ProductReview::where('product_id', $product->id)
            ->approved()
            ->orderBy('created_at', 'desc')
            ->get(['id', 'author_name', 'rating', 'title', 'comment', 'is_verified_buyer', 'created_at']);

        $totalReviews = $reviews->count();
        $averageRating = $totalReviews > 0 ? round($reviews->avg('rating'), 1) : 5.0;

        // Rating breakdown
        $breakdown = [
            5 => $reviews->where('rating', 5)->count(),
            4 => $reviews->where('rating', 4)->count(),
            3 => $reviews->where('rating', 3)->count(),
            2 => $reviews->where('rating', 2)->count(),
            1 => $reviews->where('rating', 1)->count(),
        ];

        // Check verified purchaser eligibility
        $user = auth('sanctum')->user() ?? auth()->user();
        $hasPurchased = false;
        $alreadyReviewed = false;

        if ($user) {
            $hasPurchased = OrderItem::where('product_id', $product->id)
                ->whereHas('order', function ($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->whereNotIn('status', ['cancelled', 'failed']);
                })
                ->exists();

            $alreadyReviewed = ProductReview::where('product_id', $product->id)
                ->where('user_id', $user->id)
                ->exists();
        }

        return $this->successResponse([
            'average_rating' => $averageRating,
            'total_reviews' => $totalReviews,
            'breakdown' => $breakdown,
            'reviews' => $reviews,
            'can_review' => $hasPurchased && ! $alreadyReviewed,
            'has_purchased' => $hasPurchased,
            'already_reviewed' => $alreadyReviewed,
            'is_logged_in' => (bool) $user,
        ], 'Product reviews fetched successfully.');
    }

    public function store(Request $request, string $productSlug): JsonResponse
    {
        $product = Product::where('slug', $productSlug)->firstOrFail();

        $user = auth('sanctum')->user() ?? auth()->user();

        if (! $user) {
            return $this->errorResponse('Please log in to submit a verified buyer review.', 401);
        }

        // Strict verification: user must have purchased this product
        $hasPurchased = OrderItem::where('product_id', $product->id)
            ->whereHas('order', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->whereNotIn('status', ['cancelled', 'failed']);
            })
            ->exists();

        if (! $hasPurchased) {
            return $this->errorResponse('Only verified buyers who purchased this product can leave a review.', 403);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'author_name' => 'required|string|max:100',
            'title' => 'nullable|string|max:150',
            'comment' => 'required|string|min:5|max:2000',
        ]);

        $existingReview = ProductReview::where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existingReview) {
            $existingReview->update([
                'rating' => (int) $validated['rating'],
                'author_name' => $validated['author_name'],
                'title' => $validated['title'] ?? null,
                'comment' => $validated['comment'],
            ]);

            return $this->successResponse($existingReview, 'Your verified review has been updated.');
        }

        $review = ProductReview::create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'author_name' => $validated['author_name'] ?: ($user->name ?? 'Verified Buyer'),
            'author_email' => $user->email,
            'rating' => (int) $validated['rating'],
            'title' => $validated['title'] ?? null,
            'comment' => $validated['comment'],
            'is_verified_buyer' => true,
            'is_approved' => true,
        ]);

        return $this->successResponse($review, 'Thank you! Your verified review has been published.', 201);
    }
}
