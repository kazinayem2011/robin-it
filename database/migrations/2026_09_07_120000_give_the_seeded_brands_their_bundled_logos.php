<?php

use App\Models\Brand;
use Illuminate\Database\Migrations\Migration;

/**
 * Point the seeded brands at the logo files that already ship with the repo.
 *
 * The homepage brand row used to be a hardcoded list of fourteen names and
 * fourteen file paths, entirely separate from the brands table — so uploading a
 * logo in the admin changed the mega menu and never touched the homepage. The
 * row reads the table now, which means those files need to belong to a brand
 * rather than to a constant in a component.
 *
 * Only fills a blank. A brand whose logo an admin has already uploaded keeps
 * it, and a name that is not in the table is skipped rather than created —
 * this is here to carry existing artwork across, not to invent brands.
 *
 * The map lives on the Brand model, so this and the seeder that builds a
 * fresh install cannot disagree about which files exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Brand::BUNDLED_LOGOS as $slug => $path) {
            // The file has to actually be there: a path to nothing renders a
            // broken image, which is worse than the name it would otherwise
            // fall back to.
            if (! is_file(public_path(ltrim($path, '/')))) {
                continue;
            }

            Brand::where('slug', $slug)
                ->where(function ($query) {
                    $query->whereNull('logo_path')->orWhere('logo_path', '');
                })
                ->update(['logo_path' => $path]);
        }
    }

    public function down(): void
    {
        // Only the ones this put there; anything uploaded since is left alone.
        Brand::whereIn('logo_path', array_values(Brand::BUNDLED_LOGOS))
            ->update(['logo_path' => null]);
    }
};
