<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money actually received against an order.
 *
 * An order carried a payment_status of unpaid or paid and nothing else — a
 * flag, with no amount, no date and no method behind it. So a customer who put
 * ৳20,000 down on a ৳2,45,000 build was recorded exactly like one who had paid
 * nothing, and there was nowhere to look up what a branch was owed.
 *
 * Shaped like the refunds table it sits beside, and append-only for the same
 * reason: a payment received is a fact, and a mistake is corrected by recording
 * the correction rather than by editing history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Signed: a negative row is a correction to one taken in error.
            $table->decimal('amount', 12, 2);

            $table->string('method', 40);
            $table->string('reference')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_by_name');
            $table->date('received_on');

            $table->timestamps();
            $table->index(['order_id', 'received_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
