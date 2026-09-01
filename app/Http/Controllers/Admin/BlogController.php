<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogRequest;
use App\Models\BlogPost;
use App\Support\SlugFactory;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tech journal & blog articles manager.
 */
class BlogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Blogs', [
            'blogs' => BlogPost::latest()->get(),
        ]);
    }

    public function store(BlogRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['slug'] = SlugFactory::unique(BlogPost::class, $validated['title']);
        // `boolean` rules leave the key absent when the field isn't posted at all.
        $validated['is_published'] = (bool) ($validated['is_published'] ?? false);
        $validated['published_at'] = $validated['is_published'] ? now() : null;

        $blog = BlogPost::create($validated);

        return $this->successResponse($blog, 'Blog post published successfully.', 201);
    }

    public function update(BlogRequest $request, int $id): JsonResponse
    {
        $blog = BlogPost::findOrFail($id);
        $validated = $request->validated();

        $validated['is_published'] = (bool) ($validated['is_published'] ?? false);

        if ($validated['is_published'] && ! $blog->published_at) {
            $validated['published_at'] = now();
        }

        $blog->update($validated);

        return $this->successResponse($blog, 'Blog post updated successfully.');
    }

    public function destroy(int $id): JsonResponse
    {
        BlogPost::findOrFail($id)->delete();

        return $this->successResponse([], 'Blog post removed.');
    }
}
