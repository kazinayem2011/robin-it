<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the shop spends that is not stock.
 *
 * Stock is deliberately not an expense here. Units bought are inventory until
 * they are sold — they are already costed through the stock ledger and reach
 * the accounts as cost of goods sold on the order that sells them. Recording a
 * delivery here as well would count the same money twice and make every month
 * with a big delivery look like a loss.
 *
 * So this is rent, wages, the courier's bill, packaging, advertising, fees:
 * money that leaves and does not come back as something on a shelf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40)->index();
            $table->decimal('amount', 12, 2);
            $table->string('description');
            // The date the cost belongs to, which is not always the day it was
            // typed in — a January bill entered in February is January's.
            $table->date('incurred_on')->index();
            $table->string('reference', 100)->nullable();
            $table->text('note')->nullable();

            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            // Who recorded it. Kept when the account is removed, like the rest
            // of the audit trail.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // Every report reads by period, and most also group by category.
            $table->index(['incurred_on', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
