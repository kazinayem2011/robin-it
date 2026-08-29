<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the shop has asked a supplier for, before any of it arrives.
 *
 * Stock receipts record what turned up. Nothing recorded what was ordered, so
 * between placing an order and it arriving the shop had no record of it at all:
 * no "twenty of these are on the way" when a customer asks, no way to tell a
 * supplier who shipped fifteen that they owe five, and nothing to check an
 * invoice against.
 *
 * Deliberately not an expense. Buying stock converts cash into goods, and both
 * sit on the same side of the books until the goods are sold — the cost lands
 * in profit through cost of goods when it does, which is what the P&L already
 * reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();

            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            // Kept alongside, so an order still names its supplier if that
            // record is later removed — the same reason receipts do it.
            $table->string('supplier_name')->nullable();

            // Which branch it is coming into. Null means the shop decides on
            // arrival, which is what a single-branch shop always means.
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();

            /*
             * draft    — being written, changes nothing
             * sent     — with the supplier; this is what "on order" counts
             * partial  — some of it has arrived
             * received — all of it has
             * cancelled— it is not coming
             */
            $table->string('status', 20)->default('draft');

            $table->date('expected_on')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ordered_by_name')->nullable();
            $table->timestamp('sent_at')->nullable();

            // Cached totals, so a list of orders does not need every line.
            $table->unsignedInteger('total_quantity')->default(0);
            $table->decimal('total_cost', 12, 2)->default(0);

            $table->timestamps();

            $table->index(['status', 'expected_on']);
            $table->index('supplier_id');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity')->default(0);

            /*
             * How many have actually turned up, across however many deliveries
             * it took. The difference between this and quantity is the whole
             * point of the record: it is what the supplier still owes.
             */
            $table->unsignedInteger('quantity_received')->default(0);

            // What the supplier quoted. Carried onto the receipt when the goods
            // arrive, so cost of goods is right without anybody retyping it.
            $table->decimal('unit_cost', 12, 2)->nullable();

            $table->timestamps();

            $table->index(['purchase_order_id', 'product_id']);
        });

        // Which order a delivery was against, where it was against one.
        Schema::table('stock_receipts', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('supplier_name')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });

        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
