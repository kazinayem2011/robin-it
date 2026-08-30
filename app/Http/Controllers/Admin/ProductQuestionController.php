<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The moderation queue for product questions.
 *
 * Unanswered first, because an unanswered question on a product page is a
 * shopper who is still deciding, and the oldest one has been waiting longest.
 */
class ProductQuestionController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->string('filter')->toString() ?: 'unanswered';

        $questions = ProductQuestion::query()
            ->with(['product:id,name,slug', 'answerer:id,name'])
            ->when($filter === 'unanswered', fn ($q) => $q->whereNull('answer'))
            ->when($filter === 'unpublished', fn ($q) => $q->where('is_published', false))
            ->orderByRaw('answer IS NOT NULL')
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ProductQuestion $q) => [
                'id' => $q->id,
                'name' => $q->name,
                'question' => $q->question,
                'answer' => $q->answer,
                'is_published' => $q->is_published,
                'created_at' => $q->created_at?->toDateTimeString(),
                'answered_by_name' => $q->answerer?->name,
                'product' => $q->product ? [
                    'name' => $q->product->name,
                    'slug' => $q->product->slug,
                ] : null,
            ]);

        return Inertia::render('Admin/ProductQuestions', [
            'questions' => $questions,
            'filters' => ['filter' => $filter],
            'counts' => [
                'unanswered' => ProductQuestion::whereNull('answer')->count(),
                'unpublished' => ProductQuestion::where('is_published', false)->count(),
            ],
        ]);
    }

    /**
     * Answer a question, and publish it in the same movement.
     *
     * Answering without publishing leaves the work invisible, and every
     * separate "now publish it" step is one somebody forgets — so an answer
     * publishes unless explicitly told not to.
     */
    public function answer(Request $request, int $id): JsonResponse
    {
        $question = ProductQuestion::findOrFail($id);

        $validated = $request->validate([
            'answer' => 'required|string|min:2|max:2000',
            'is_published' => 'nullable|boolean',
        ]);

        $question->update([
            'answer' => $validated['answer'],
            'answered_by' => Auth::id(),
            'answered_at' => now(),
            'is_published' => $validated['is_published'] ?? true,
        ]);

        return $this->successResponse($question, 'Answer saved.');
    }

    /** Show or hide a question without touching its answer. */
    public function publish(Request $request, int $id): JsonResponse
    {
        $question = ProductQuestion::findOrFail($id);

        $question->update([
            'is_published' => $request->boolean('is_published'),
        ]);

        return $this->successResponse(
            $question,
            $question->is_published ? 'Question published.' : 'Question hidden.'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        ProductQuestion::findOrFail($id)->delete();

        return $this->successResponse(null, 'Question deleted.');
    }
}
