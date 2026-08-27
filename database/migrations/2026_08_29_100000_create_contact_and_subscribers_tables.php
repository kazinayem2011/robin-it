<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for "Contact Us" to go, and somewhere for "Subscribe" to land.
 *
 * The footer has linked to /about and /contact since the site was built and
 * both were 404s, and its newsletter box was a form with
 * onSubmit={(e) => e.preventDefault()} — an email address typed into it went
 * nowhere at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('subject');
            $table->text('message');

            // new -> open once somebody has answered -> closed when it is done.
            $table->string('status')->default('new')->index();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            // Who sent it, for spam that arrives in bulk.
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            // The inbox is read newest-first, filtered by status.
            $table->index(['status', 'created_at']);
        });

        Schema::create('contact_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_message_id')->constrained()->cascadeOnDelete();
            // Null once the member of staff who wrote it has left.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->text('body');
            // A reply that could not be emailed is still a record of the answer.
            $table->boolean('emailed')->default(false);
            $table->timestamps();
        });

        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('status')->default('subscribed')->index();
            /*
             * How someone leaves without signing in. Every marketing email has
             * to carry an unsubscribe link, and the address alone must not be
             * enough — otherwise anyone could unsubscribe anyone.
             */
            $table->string('token', 64)->unique();
            $table->string('source')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_replies');
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('subscribers');
    }
};
