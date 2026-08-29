<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of somebody changing an order after it was placed.
 *
 * Orders could not be changed at all, so a customer adding a stick of RAM to a
 * pending order meant cancelling and starting again — losing the order number,
 * the tracking link already texted to them, and any deposit's connection to it.
 *
 * Allowing an edit means allowing a member of staff to change what a customer
 * agreed to pay after they agreed to it, so every change is written down: who,
 * when, what the total was before and after, and what actually moved. Without
 * that the feature is a way to quietly alter a bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Kept alongside, so the record still names who did it after that
            // account is closed.
            $table->string('edited_by_name')->nullable();

            $table->decimal('total_before', 12, 2);
            $table->decimal('total_after', 12, 2);

            /*
             * What changed, line by line, as it was at the time: product name,
             * the quantity before and after. Stored rather than derived,
             * because the products themselves get renamed and deleted and this
             * has to still read correctly in a year.
             */
            $table->json('changes');

            $table->string('reason')->nullable();

            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_edits');
    }
};
