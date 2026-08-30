<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Questions a shopper asks before buying.
 *
 * Reviews are retrospective; these are the blocker between a shopper and a
 * purchase — "does it take a second SSD?", "is the keyboard Bengali?" — and the
 * answer is what converts.
 */
class ProductQuestionController extends Controller
{
    public function index(string $productSlug): JsonResponse
    {
        $product = Product::where('slug', $productSlug)->firstOrFail();

        $questions = $product->questions()
            ->public()
            ->with('answerer:id,name')
            ->get()
            ->map(fn (ProductQuestion $q) => [
                'id' => $q->id,
                // First name only. A question is public and a full name plus a
                // purchase is more than a shopper agreed to publish.
                'name' => explode(' ', trim($q->name))[0] ?: 'Customer',
                'question' => $q->question,
                'answer' => $q->answer,
                'answered_by' => $q->answerer?->name,
                'answered_at' => $q->answered_at?->toDateString(),
                'asked_at' => $q->created_at->toDateString(),
            ]);

        return ApiEnvelope::success([
            'questions' => $questions,
            'total' => $questions->count(),
            'answered' => $questions->whereNotNull('answer')->count(),
        ]);
    }

    public function store(Request $request, string $productSlug): JsonResponse
    {
        $product = Product::where('slug', $productSlug)->firstOrFail();

        $user = Auth::user();

        $validated = $request->validate([
            // A signed-in shopper should not retype what the shop already
            // knows, so both are optional when there is a session.
            'name' => [$user ? 'nullable' : 'required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            // Long enough to be a question. "?" alone is not one, and a
            // moderation queue full of them is a queue nobody reads.
            'question' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $question = $product->questions()->create([
            'user_id' => $user?->id,
            'name' => $validated['name'] ?? $user?->name ?? 'Customer',
            'email' => $validated['email'] ?? $user?->email,
            'question' => $validated['question'],
            // Never straight to the page. Moderation queues only work when the
            // default is "not yet".
            'is_published' => false,
        ]);

        return ApiEnvelope::success(
            ['id' => $question->id],
            'Thanks — we will answer this shortly.',
            201
        );
    }
}
