<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A count of what is actually on the shelves.
 *
 * Corrections could only be made one product at a time through a modal, which
 * is right for "this card arrived broken" and unusable for the thing shops
 * actually do — walk the aisle and count everything. Without it the recorded
 * stock drifts from the real stock and never comes back, and the valuation and
 * cost of goods drift with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_takes', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();

            // A count is of one branch's shelves. Counting "the shop" across
            // four showrooms at once is not a thing anybody can actually do.
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('counted_by_name');

            $table->text('note')->nullable();

            // Kept rather than recomputed: what the shelves were worth on the
            // day is not what today's costs would make it.
            $table->unsignedInteger('lines_counted')->default(0);
            $table->unsignedInteger('lines_changed')->default(0);
            $table->integer('net_units')->default(0);
            $table->decimal('value_change', 14, 2)->default(0);

            $table->timestamps();
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_takes');
    }
};
