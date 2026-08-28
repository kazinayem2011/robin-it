<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which physical unit went to which customer.
 *
 * The warranty form already asked for a serial number, and nothing recorded
 * one — so when somebody claimed, the shop could not tell whether they had
 * bought it here, when the warranty started, or whether that same serial had
 * been claimed before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_serials', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();

            /*
             * Unique across the shop. A serial identifies one physical object;
             * the same string appearing twice means somebody mistyped it or a
             * supplier sent a duplicate, and both are worth refusing at the
             * point of entry rather than discovering during a warranty claim.
             */
            $table->string('serial', 120)->unique();

            // Where it is, while the shop still has it.
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('in_stock')->index();

            // How it arrived, and where it went.
            $table->foreignId('stock_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sold_at')->nullable();

            // What the manufacturer's cover runs to, worked out at the sale.
            $table->date('warranty_until')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_serials');
    }
};
