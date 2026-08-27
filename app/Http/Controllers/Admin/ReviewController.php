<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewStatusRequest;
use App\Models\ProductReview;
use App\Support\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer reviews moderation.
 *
 * Reviews are published on submission (they can only come from verified
 * buyers), but nothing let an admin take one down. This is that screen.
 */
class ReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->input('status', 'all');
        $search = trim((string) $request->input('search', ''));

        $query = ProductReview::with(['product:id,name,slug', 'user:id,name,email'])->latest();

        if ($status === 'published') {
            $query->where('is_approved', true);
        } elseif ($status === 'hidden') {
            $query->where('is_approved', false);
        }

        if ($search !== '') {
            $term = SearchTerm::contains($search);

            $query->where(function ($q) use ($term) {
                $q->where('comment', 'LIKE', $term)
                    ->orWhere('title', 'LIKE', $term)
                    ->orWhere('author_name', 'LIKE', $term)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'LIKE', $term));
            });
        }

        return Inertia::render('Admin/Reviews', [
            'reviews' => $query->paginate(20)->withQueryString(),
            'filters' => ['status' => $status, 'search' => $search],
            'counts' => [
                'all' => ProductReview::count(),
                'published' => ProductReview::where('is_approved', true)->count(),
                'hidden' => ProductReview::where('is_approved', false)->count(),
            ],
        ]);
    }

    /**
     * Publish or hide a single review.
     */
    public function updateStatus(ReviewStatusRequest $request, int $id): JsonResponse
    {
        $review = ProductReview::findOrFail($id);
        $approved = $request->validated()['is_approved'];

        $review->update(['is_approved' => $approved]);

        return $this->successResponse(
            $review,
            $approved ? 'Review published.' : 'Review hidden from the storefront.'
        );
    }

    /**
     * Permanently remove a review.
     */
    public function destroy(int $id): JsonResponse
    {
        ProductReview::findOrFail($id)->delete();

        return $this->successResponse([], 'Review deleted.');
    }
}
