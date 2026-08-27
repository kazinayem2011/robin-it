<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money given back, recorded as an event rather than a flag.
 *
 * `payment_status` could be set to 'refunded' and that was the whole record:
 * no amount, no date, no method, no reason, and no way to represent giving
 * back part of an order — which is what actually happens when one item of
 * three comes back.
 *
 * Deliberately separate from returns. Goods coming back and money going back
 * are different events that often but not always accompany each other: a
 * damaged item may be refunded without being returned, and an exchange returns
 * goods without refunding anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 30);
            // The bKash transaction id, the bank reference, the receipt number
            // — whatever proves the money moved.
            $table->string('reference', 120)->nullable();
            $table->string('reason', 60);
            $table->text('note')->nullable();
            // Who authorised it. Kept when the account goes, like the rest of
            // the audit trail.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // When the money went back, which need not be when it was typed in.
            $table->date('refunded_on');
            $table->timestamps();

            $table->index(['refunded_on', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
