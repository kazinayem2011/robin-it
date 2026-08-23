<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock split across the branches that actually hold it.
 *
 * Until now the shop had one pooled number, so nobody could answer "is it in
 * the Uttara showroom or the Chattogram one?" — a question customers ask on the
 * phone constantly.
 *
 * `products.stock_quantity` keeps its meaning as the total the shop holds and
 * stays the maintained sum of the per-branch rows, so every existing query,
 * badge and report continues to be correct without being rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // A branch that keeps inventory. A pickup point or office would not.
            $table->boolean('holds_stock')->default(true)->after('is_active');

            // Where online orders are picked from. Exactly one branch does this;
            // the rest hold walk-in stock only.
            $table->boolean('fulfils_online')->default(false)->after('holds_stock');

            $table->index(['holds_stock', 'is_active']);
        });

        // Per-branch balances. The ledger remains the source of truth; this is
        // the same cached-balance arrangement products already use.
        Schema::create('product_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'product_variant_id', 'store_id'], 'product_stock_unique');
            $table->index(['store_id', 'quantity']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // Which branch the movement happened at. Nullable because rows
            // written before this existed genuinely have no location.
            $table->foreignId('store_id')->nullable()->after('product_variant_id')
                ->constrained()->nullOnDelete();

            $table->index(['store_id', 'created_at']);
        });

        $this->seedExistingStock();
    }

    /**
     * Put everything the shop currently holds at the online branch.
     *
     * That is the only honest starting point: the existing number is a single
     * pool with no location attached, and inventing a split across branches
     * would be worse than admitting it all sits in one place until someone
     * transfers it.
     */
    private function seedExistingStock(): void
    {
        $online = DB::table('stores')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->first();

        if (! $online) {
            return;
        }

        DB::table('stores')->where('id', $online->id)->update([
            'fulfils_online' => true,
            'holds_stock' => true,
        ]);

        $now = now();
        $rows = [];

        foreach (DB::table('products')->select('id', 'stock_quantity', 'has_variants')->get() as $product) {
            if ($product->has_variants) {
                continue;
            }

            $rows[] = [
                'product_id' => $product->id,
                'product_variant_id' => null,
                'store_id' => $online->id,
                'quantity' => (int) $product->stock_quantity,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (DB::table('product_variants')->select('id', 'product_id', 'stock_quantity')->get() as $variant) {
            $rows[] = [
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'store_id' => $online->id,
                'quantity' => (int) $variant->stock_quantity,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('product_stock')->insert($chunk);
        }

        // Existing ledger rows belong to that branch too, so the history reads
        // consistently with the balances it explains.
        DB::table('stock_movements')->whereNull('store_id')->update(['store_id' => $online->id]);
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropIndex(['store_id', 'created_at']);
            $table->dropColumn('store_id');
        });

        Schema::dropIfExists('product_stock');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['holds_stock', 'is_active']);
            $table->dropColumn(['holds_stock', 'fulfils_online']);
        });
    }
};
