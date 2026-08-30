<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer questions about a product, answered by staff.
 *
 * Reviews say whether a thing was good after the fact. Questions are asked
 * before the sale — "does this take a second SSD?", "is the keyboard
 * Bengali?" — and the answer converts, because the shop is trusted to know.
 * The competitor gives them a tab of their own beside Reviews.
 *
 * Published separately from answered: a question with no answer is still worth
 * showing to staff and worth hiding from shoppers, and a question that should
 * never have been asked in public has to be removable without deleting the
 * customer's account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Nullable: a shopper who has not signed in may still ask, which is
            // the point — requiring an account loses the question.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();

            $table->text('question');
            $table->text('answer')->nullable();

            // Who answered, kept even if that member of staff later leaves.
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();

            // Off by default. A public product page is not the place for an
            // unreviewed question, and moderation queues only work when the
            // default is "not yet".
            $table->boolean('is_published')->default(false);

            $table->timestamps();

            // The product page reads exactly this: published questions for one
            // product, newest first.
            $table->index(['product_id', 'is_published', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_questions');
    }
};
