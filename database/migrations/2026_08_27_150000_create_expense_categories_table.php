<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spending categories become the shop's to maintain.
 *
 * They were a constant in the Expense model — a list picked when the feature
 * was written, which no shop could change without a deploy. Every business
 * spends money on something the next one does not.
 *
 * The ten that were hardcoded are seeded, so nothing is lost and an existing
 * expense keeps the category it was filed under. `slug` is kept alongside the
 * name because the old rows reference categories by that string, and because a
 * renamed category should still be the same category.
 */
return new class extends Migration
{
    /** What the model used to hold, in the order it listed them. */
    private const SEEDED = [
        ['rent', 'Rent & premises'],
        ['salaries', 'Salaries & wages'],
        ['utilities', 'Utilities'],
        ['delivery', 'Courier & delivery'],
        ['packaging', 'Packaging & consumables'],
        ['marketing', 'Marketing & advertising'],
        ['equipment', 'Equipment & software'],
        ['fees', 'Bank & transaction fees'],
        ['maintenance', 'Repairs & maintenance'],
        ['other', 'Other'],
    ];

    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach (self::SEEDED as $position => [$slug, $name]) {
            DB::table('expense_categories')->insert([
                'name' => $name,
                'slug' => $slug,
                'sort_order' => $position,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('expenses', function (Blueprint $table) {
            // Nullable and nullOnDelete: a category that is genuinely removed
            // must not take the spending recorded against it with it.
            $table->foreignId('expense_category_id')
                ->nullable()
                ->after('id')
                ->constrained('expense_categories')
                ->nullOnDelete();
        });

        // Carry every existing expense across by the string it was filed under.
        foreach (DB::table('expense_categories')->get(['id', 'slug']) as $category) {
            DB::table('expenses')
                ->where('category', $category->slug)
                ->update(['expense_category_id' => $category->id]);
        }

        /*
         * Anything filed under a string that is not one of the seeded ten — a
         * category added by hand at some point — becomes a category of its own
         * rather than being dropped on the floor.
         */
        $orphans = DB::table('expenses')
            ->whereNull('expense_category_id')
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category');

        $next = count(self::SEEDED);

        foreach ($orphans as $slug) {
            $id = DB::table('expense_categories')->insertGetId([
                'name' => ucfirst(str_replace(['_', '-'], ' ', (string) $slug)),
                'slug' => $slug,
                'sort_order' => $next++,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('expenses')->where('category', $slug)->update(['expense_category_id' => $id]);
        }

        Schema::table('expenses', function (Blueprint $table) {
            /*
             * Both indexes, not just the composite one. `category` was declared
             * with ->index() as well, and SQLite refuses to drop a column that
             * still has an index on it — MySQL drops them with the column and
             * says nothing, so this only shows up on the other driver.
             */
            $table->dropIndex(['incurred_on', 'category']);
            $table->dropIndex(['category']);
            $table->dropColumn('category');
            $table->index(['incurred_on', 'expense_category_id']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('id');
            $table->index(['category']);
        });

        foreach (DB::table('expense_categories')->get(['id', 'slug']) as $category) {
            DB::table('expenses')
                ->where('expense_category_id', $category->id)
                ->update(['category' => $category->slug]);
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['incurred_on', 'expense_category_id']);
            $table->dropConstrainedForeignId('expense_category_id');
            $table->index(['incurred_on', 'category']);
        });

        Schema::dropIfExists('expense_categories');
    }
};
