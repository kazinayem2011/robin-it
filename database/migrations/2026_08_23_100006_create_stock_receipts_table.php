<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stock receipt is the only way units enter the shelf.
 *
 * One receipt covers one delivery from one supplier and can carry many lines,
 * so a single invoice stays a single record. Receiving it writes one `purchase`
 * movement per line into the stock ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('supplier_name')->nullable();
            $table->string('invoice_number')->nullable();
            $table->date('received_on');
            $table->text('note')->nullable();

            // Sum of the lines, kept for reporting without re-aggregating.
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->unsignedInteger('total_quantity')->default(0);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('received_on');
        });

        Schema::create('stock_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_receipt_items');
        Schema::dropIfExists('stock_receipts');
    }
};
