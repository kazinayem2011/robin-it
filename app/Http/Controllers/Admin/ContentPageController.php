<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContentPageRequest;
use App\Models\ContentPage;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pages the shop writes itself.
 *
 * About, privacy, terms and the return policy were links in the footer with
 * nothing behind them, and About's copy lived in the JSX — so changing a word
 * about the business needed a developer and a deploy.
 */
class ContentPageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Pages', [
            'pages' => ContentPage::with('editor:id,name')
                ->orderByDesc('is_system')
                ->orderBy('title')
                ->get()
                ->map(fn (ContentPage $p) => [
                    ...$p->only([
                        'id', 'slug', 'title', 'subtitle', 'body',
                        'meta_title', 'meta_description', 'is_published', 'is_system',
                    ]),
                    // Built-in pages have their own short URLs; anything the
                    // shop adds lives under /p/.
                    'url' => $p->is_system ? '/'.$p->slug : '/p/'.$p->slug,
                    'updated_by_name' => $p->editor?->name,
                    'updated_at' => $p->updated_at?->diffForHumans(),
                ]),
        ]);
    }

    public function store(ContentPageRequest $request): JsonResponse
    {
        $page = new ContentPage($request->validated());
        $page->updated_by = $request->user()->id;
        $page->save();

        return $this->successResponse(
            $page->only(['id', 'slug', 'title']),
            "\"{$page->title}\" is live at /p/{$page->slug}.",
            201
        );
    }

    public function update(ContentPageRequest $request, int $id): JsonResponse
    {
        $page = ContentPage::findOrFail($id);
        $validated = $request->validated();

        /*
         * A system page keeps its address. The footer links to /privacy and
         * /terms by name, so letting the slug move would break those links
         * from a screen that gives no sign it was about to.
         */
        if ($page->is_system) {
            unset($validated['slug']);
        }

        $page->fill($validated);
        $page->updated_by = $request->user()->id;
        $page->save();

        return $this->successResponse($page->only(['id', 'slug', 'title']), 'Page saved.');
    }

    public function destroy(int $id): JsonResponse
    {
        $page = ContentPage::findOrFail($id);

        if ($page->is_system) {
            return $this->errorResponse(
                "\"{$page->title}\" is linked from the footer and the law expects it to exist. "
                    .'The words are yours to change; the page is not yours to delete.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        $page->delete();

        return $this->successResponse([], 'Page removed.');
    }
}
