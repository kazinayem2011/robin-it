<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every existing balance a ledger row.
 *
 * Products that predate the ledger already carry a quantity. Without this the
 * ledger would sum to zero while the shelf says otherwise, and every product
 * would look like it had drifted. One `opening` row per product records the
 * balance as it stood when tracking began.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('products')
            ->select('id', 'stock_quantity')
            ->orderBy('id')
            ->chunk(200, function ($products) use ($now) {
                $rows = [];

                foreach ($products as $product) {
                    $quantity = (int) $product->stock_quantity;

                    if ($quantity === 0) {
                        continue;
                    }

                    $rows[] = [
                        'product_id' => $product->id,
                        'product_variant_id' => null,
                        'quantity' => $quantity,
                        'type' => 'opening',
                        'balance_after' => $quantity,
                        'note' => 'Balance carried over when stock tracking was introduced',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('stock_movements')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        DB::table('stock_movements')->where('type', 'opening')->delete();
    }
};
