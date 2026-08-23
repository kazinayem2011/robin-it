<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only stock ledger.
 *
 * Stock used to be a single mutable integer that the admin form could overwrite
 * with any number, which let a stale form resurrect already-sold units and let a
 * re-cancelled order invent stock that never existed. Every change now has a row,
 * a reason and an author, and `stock_quantity` is only the cached balance of it.
 *
 * Rows are never updated or deleted. A mistake is corrected with another row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            // product_id is always populated, even for a variant movement, so
            // "everything that happened to this product" stays one cheap query.
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            // Signed: positive adds to the shelf, negative takes off it.
            $table->integer('quantity');

            // purchase | sale | cancellation | return | write_off | adjustment | conversion | opening
            $table->string('type', 32);

            // Balance immediately after this row, so the ledger can be audited
            // without replaying it from the beginning.
            $table->integer('balance_after');

            // What caused it — an order, a stock receipt, or nothing for a manual
            // adjustment. Polymorphic so new sources do not need a schema change.
            $table->nullableMorphs('reference');

            // Mandatory for adjustments and write-offs; free text otherwise.
            $table->string('reason')->nullable();
            $table->text('note')->nullable();

            // Unit cost on purchases, for valuation and margin reporting.
            $table->decimal('unit_cost', 10, 2)->nullable();

            // Who did it. Null for system-generated movements (customer orders).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['product_id', 'created_at']);
            $table->index(['product_variant_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
