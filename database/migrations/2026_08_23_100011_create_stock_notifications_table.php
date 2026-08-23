<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People waiting for something that is sold out.
 *
 * A shopper who arrives to find a card gone currently just leaves. The ledger
 * already knows the exact moment stock crosses back above zero, so the only
 * missing part was somewhere to record who asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Null means the product is sold as a single item; otherwise the
            // request is for one specific option, since the others may be fine.
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('email');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Set once the "it's back" mail has gone out. Rows are kept rather
            // than deleted so a second request can be told it already notified.
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            // One outstanding request per person per item. Notified rows are
            // excluded from this by being cleared out, so someone can ask
            // again the next time it sells out.
            $table->unique(['product_id', 'product_variant_id', 'email'], 'stock_notify_unique');
            $table->index(['product_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_notifications');
    }
};
