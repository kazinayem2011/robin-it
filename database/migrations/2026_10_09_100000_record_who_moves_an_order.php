<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time an order changes status: from what, to what, when, and who.
 *
 * Payments, refunds and edits each named who made them; the status never did.
 * A laptop order on live went pending → shipped → returned in two minutes, and
 * afterwards there was no way to say who dispatched it or who took it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Kept alongside, so the record still names who did it after that
            // account is closed.
            $table->string('by_name')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_changes');
    }
};
