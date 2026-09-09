<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which shelf comes first is the shop's decision, not the database's.
 *
 * Nothing ordered categories anywhere. Neither the mega-menu query nor the
 * `children` relation carried an `orderBy`, so the menu, the footer and every
 * picker showed whatever order the engine happened to return — in practice
 * insertion order, but only by habit, and nothing a shopkeeper could change.
 * A shop wants Laptop before Office Equipment because that is what sells, and
 * had no way to say so.
 *
 * Backfilled by id within each parent rather than by name, so the order an
 * admin is looking at today survives the migration. Alphabetical would have
 * been just as arbitrary and would have rearranged the menu on deploy.
 *
 * Positions are per parent: a subcategory's 0 is first among its siblings, not
 * first in the shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('slug');

            /*
             * The menu reads children by parent and in order, which is this
             * index exactly. Without it the ordering added below turns every
             * menu build into a filesort over the whole table.
             */
            $table->index(['parent_id', 'position']);
        });

        // Grouped by parent, so each set of siblings is numbered from zero.
        $rows = DB::table('categories')
            ->select('id', 'parent_id')
            ->orderBy('parent_id')
            ->orderBy('id')
            ->get();

        $seen = [];

        foreach ($rows as $row) {
            $key = $row->parent_id ?? 0;
            $seen[$key] = ($seen[$key] ?? -1) + 1;

            DB::table('categories')
                ->where('id', $row->id)
                ->update(['position' => $seen[$key]]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'position']);
            $table->dropColumn('position');
        });
    }
};
