<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    /** Upper bound on how many articles one request can pull. */
    private const MAX_LIMIT = 50;

    /**
     * Get published blog posts / tech articles.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => 'nullable|string|max:50',
            'limit' => 'nullable|integer|min:1|max:'.self::MAX_LIMIT,
        ]);

        $limit = min((int) ($validated['limit'] ?? 10), self::MAX_LIMIT);

        $query = BlogPost::published()->orderBy('published_at', 'desc');

        $category = $validated['category'] ?? null;
        if ($category && $category !== 'all') {
            $query->where('category', $category);
        }

        return $this->successResponse(
            $query->take($limit)->get(),
            'Articles fetched successfully.'
        );
    }

    /**
     * Get a single blog post by slug.
     */
    public function show(string $slug): JsonResponse
    {
        $post = BlogPost::published()->where('slug', $slug)->first();

        if (! $post) {
            return $this->errorResponse('Article not found.', 404, ApiCode::NOT_FOUND);
        }

        return $this->successResponse($post, 'Article details fetched successfully.');
    }
}
