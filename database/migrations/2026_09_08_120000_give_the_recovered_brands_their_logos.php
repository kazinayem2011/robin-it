<?php

use App\Models\Brand;
use App\Services\CategoryService;
use Illuminate\Database\Migrations\Migration;

/**
 * Fill in the four marks that were recovered after the first pass ran.
 *
 * Intel, MSI, Corsair and Samsung drew their names rather than a logo, because
 * the PNGs shipped under those names were the wrong company's artwork and were
 * deleted. The correct marks turned out to be sitting in the repo the whole
 * time as vector files nothing referenced; they are PNGs now, in the same
 * shape as the rest, so the brands can have them.
 *
 * A separate migration rather than an edit to the first one: that one has
 * already run everywhere, so changing it would be a no-op on exactly the
 * installs that need this.
 *
 * Same rule as before — fills a blank and nothing else. A brand whose logo an
 * admin has uploaded in the meantime keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Brand::BUNDLED_LOGOS as $slug => $path) {
            if (! is_file(public_path(ltrim($path, '/')))) {
                continue;
            }

            Brand::where('slug', $slug)
                ->where(function ($query) {
                    $query->whereNull('logo_path')->orWhere('logo_path', '');
                })
                ->update(['logo_path' => $path]);
        }

        // The cached mega menu carries each brand's logo, and it is dropped by
        // a `saved` event on the model. A mass update fires no events, so
        // without this the menu keeps drawing lettermarks for the six hours the
        // entry lives — which looks exactly like the logos never arrived.
        CategoryService::flush();
    }

    public function down(): void
    {
        // Only the four this one is responsible for. The nine the earlier
        // migration placed are its to undo, not this one's.
        Brand::whereIn('slug', ['intel', 'msi', 'corsair', 'samsung'])
            ->whereIn('logo_path', array_values(Brand::BUNDLED_LOGOS))
            ->update(['logo_path' => null]);

        CategoryService::flush();
    }
};
