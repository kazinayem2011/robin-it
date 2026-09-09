<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A brand shelf should know which brand it is.
 *
 * The shop lists brands the way the trade does: "ASUS" is a category under
 * Brand PC, with its own page and its own URL, the same shape Star Tech uses
 * for /lenovo-laptop. 144 of those shelves exist.
 *
 * Nothing joined them to `brands`. The two were matched on a lowercased,
 * trimmed name at render time, which is why only 100 of the 144 carry a logo,
 * why a shelf called "ASUS" cannot find the brand row named "ASUS (Network)",
 * and why renaming a brand quietly unlinks every shelf named after it.
 *
 * Backfilled on that same name match, once, while the names still agree — from
 * here the key holds the pair together and the names are free to diverge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            /*
             * Nulled rather than deleted when a brand goes: the shelf is still
             * a shelf, and the products filed on it are not the brand's to
             * take with it.
             */
            $table->foreignId('brand_id')
                ->nullable()
                ->after('parent_id')
                ->constrained()
                ->nullOnDelete();
        });

        $brands = DB::table('brands')
            ->select('id', 'name')
            ->get()
            ->mapWithKeys(fn ($brand) => [mb_strtolower(trim($brand->name)) => $brand->id]);

        DB::table('categories')
            ->whereNotNull('parent_id')
            ->select('id', 'name')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($brands) {
                foreach ($rows as $row) {
                    $id = $brands->get(mb_strtolower(trim($row->name)));

                    if ($id !== null) {
                        DB::table('categories')->where('id', $row->id)->update(['brand_id' => $id]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
