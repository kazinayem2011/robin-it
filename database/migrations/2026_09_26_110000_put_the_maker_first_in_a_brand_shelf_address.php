<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A brand shelf's address, written the way a customer would search for it.
 *
 * `/shop/component-processor-intel` is the path through the tree, which is how
 * the shop thinks about it and not how anybody looks for it. The trade writes
 * it the other way round — Star Tech's page is `/asus-laptop`, titled "Asus
 * Laptop Price in Bangladesh" — because the maker is what somebody types first.
 *
 * So the 147 brand shelves become `intel-processor`, `nvidia-graphics-card`,
 * `asus-brand-pc`: the maker, then the shelf it stands on. Shorter, and it
 * reads as a phrase rather than a route.
 *
 * The old addresses are kept in `category_slug_history`, so every existing
 * link, bookmark and indexed page redirects to the new one rather than
 * breaking. That table exists for exactly this reason.
 *
 * Written with the query builder rather than the model on purpose: a migration
 * has to keep working when the model around it has moved on, so it records the
 * history itself instead of relying on an event to do it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $shelves = DB::table('categories as c')
            ->join('brands as b', 'b.id', '=', 'c.brand_id')
            ->join('categories as p', 'p.id', '=', 'c.parent_id')
            ->select('c.id', 'c.slug', 'b.name as brand', 'p.name as parent')
            ->orderBy('c.id')
            ->get();

        $held = DB::table('categories')->pluck('slug', 'id');

        foreach ($shelves as $shelf) {
            $wanted = Str::slug($shelf->brand.' '.$shelf->parent);

            if ($wanted === '' || $wanted === $shelf->slug) {
                continue;
            }

            /*
             * Left alone rather than suffixed if somebody else holds the
             * address. A brand shelf with "-2" on the end is worse than the
             * path it already has, and the collision is worth a person seeing.
             */
            $clash = $held->search($wanted);

            if ($clash !== false && (int) $clash !== (int) $shelf->id) {
                continue;
            }

            DB::table('category_slug_history')->updateOrInsert(
                ['slug' => $shelf->slug],
                ['category_id' => $shelf->id, 'created_at' => now()],
            );

            // The shelf is moving back to an address it once left behind.
            DB::table('category_slug_history')->where('slug', $wanted)->delete();

            DB::table('categories')->where('id', $shelf->id)->update(['slug' => $wanted]);
            $held[$shelf->id] = $wanted;
        }
    }

    /**
     * Back to whatever each shelf answered at before, taken from the history
     * rather than rebuilt from the tree — the path it had is the only thing
     * that knows what it was.
     */
    public function down(): void
    {
        $history = DB::table('category_slug_history')->get(['category_id', 'slug']);

        foreach ($history as $row) {
            DB::table('categories')->where('id', $row->category_id)->update(['slug' => $row->slug]);
            DB::table('category_slug_history')->where('slug', $row->slug)->delete();
        }
    }
};
