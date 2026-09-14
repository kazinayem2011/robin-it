<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Services\CategoryService;
use App\Support\SlugFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring the category tree level with the shop it was modelled on.
 *
 * The tree is already Star Tech's — the same 15 departments in the same order,
 * and 1,390 shelves beneath them — so this is not an import. It is the drift
 * of two catalogues that were the same once: a handful of shelves renamed
 * since, a few never added, a few added here that they do not carry.
 *
 * Nothing is deleted that could be renamed or moved instead. `Intel Gaming PC`
 * became `Intel PC` on their side, and `Monitor Brands` holds thirty-one
 * makers that they list a level higher — dropping and recreating either would
 * take the products and the URLs with it, so both are edits in place. Only a
 * shelf they genuinely do not have is removed, and its products are carried up
 * to its parent first, never deleted along with it.
 *
 *   php artisan catalogue:match-startech
 *   php artisan catalogue:match-startech --apply
 */
class MatchStartechTree extends Command
{
    protected $signature = 'catalogue:match-startech
        {--apply : Make the changes, instead of only reporting them}';

    protected $description = "Rename, move, add and remove shelves so the tree matches Star Tech's exactly.";

    /** Shelves whose name changed on their side. department|parent|old => new */
    private const RENAMES = [
        'Desktop|Gaming PC|Intel Gaming PC' => 'Intel PC',
        'Desktop|Gaming PC|Ryzen Gaming PC' => 'RYZEN PC',
        'Laptop|Laptop Accessories|Laptop Charger' => 'Laptop Charger / Adapter',
        'Laptop|Laptop Accessories|Laptop Display' => 'Display',
        'Laptop|Laptop Accessories|HDD Caddy' => 'Caddy',
    ];

    /** Shelves that sit at the wrong depth here. path => new parent path ('' = department) */
    private const MOVES = [
        'Phone|Nokia' => 'Phone|Feature Phone',
        'Phone|Symphony' => 'Phone|Feature Phone',
        'Phone|Motorola' => 'Phone|Feature Phone',
        'Phone|HMD' => 'Phone|Feature Phone',
        'Phone|TCL' => 'Phone|Feature Phone',
        'Phone|XTRA' => 'Phone|Feature Phone',
    ];

    /**
     * Shelves to create. parent path => names.
     *
     * The three at department level are theirs and so they are here, but they
     * are worth knowing about: `Desktop Offer` is their promotions page and
     * `Laptop Finder` their search tool, neither holding stock of its own, and
     * `Huion` they list both as a tablet maker and under Graphics Tablet,
     * where this shop already has it.
     */
    private const ADDITIONS = [
        'Desktop' => ['Desktop Offer'],
        'Laptop' => ['Laptop Finder'],
        'Tablet' => ['Huion'],
        'Desktop|Brand PC' => ['Acer'],
        'Laptop|All Laptop' => ['Acer'],
        'Component|Graphics Card' => ['Abit'],
        'Office Equipment|Label Printer' => ['Others'],
        'Office Equipment|Toner' => ['Others'],
        'Security|NVR' => ['Tenda'],
        'Networking|Pocket Router' => ['VEMO', 'OLAX'],
        'Server & Storage|Server Rack' => ['Solitine'],
        'Accessories|Microphone' => ['Yanmai'],
    ];

    /** Shelves they do not carry. Products are carried up, then the shelf goes. */
    private const REMOVALS = [
        'Gadget|Drones',
        'Gadget|Power Bank|Vyvylabs',
        'Gadget|Power Bank|Charg',
        'Gadget|Power Bank|ACEFAST',
        'Gadget|Studio Equipment|Audio Interfaces',
        'Gadget|Studio Equipment|Switcher',
    ];

    /**
     * Their Monitor menu lists the makers beside the types; ours keeps them in
     * a `Monitor Brands` shelf. Flattening is thirty-one parent changes and
     * one empty shelf removed — no name, product or URL touched.
     */
    private const FLATTEN = 'Monitor|Monitor Brands';

    private array $did = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('Reporting only. Add --apply to make these changes.');
            $this->newLine();
        }

        $pass = function () use ($apply) {
            $this->rename($apply);
            $this->flatten($apply);
            $this->move($apply);
            $this->add($apply);
            $this->remove($apply);
        };

        /*
         * One transaction when applying, so a failure halfway leaves the tree
         * as it was rather than half-matched. A dry run writes nothing —
         * every change below is already behind `$apply` — so it needs none.
         */
        $apply ? DB::transaction($pass) : $pass();

        if ($apply) {
            CategoryService::flush();
        }

        $summary = collect($this->did)
            ->map(fn ($n, $verb) => "{$n} {$verb}".($n === 1 ? '' : 's'))
            ->implode(', ');

        $this->info($apply ? "Done: {$summary}." : "Would make: {$summary}.");

        if (! $apply) {
            $this->comment('Nothing was changed. Re-run with --apply.');
        }

        return self::SUCCESS;
    }

    /** Resolve a `Department|Shelf|Chip` path to its category. */
    private function at(string $path): ?Category
    {
        $parent = null;

        foreach (explode('|', $path) as $segment) {
            $query = Category::whereRaw('lower(name) = ?', [mb_strtolower($segment)]);
            $parent === null
                ? $query->whereNull('parent_id')
                : $query->where('parent_id', $parent);

            $found = $query->first();

            if (! $found) {
                return null;
            }

            $parent = $found->id;
        }

        return Category::find($parent);
    }

    private function say(string $verb, string $detail): void
    {
        $this->did[$verb] = ($this->did[$verb] ?? 0) + 1;
        $this->line(sprintf('  <fg=cyan>%-8s</> %s', $verb, $detail));
    }

    private function rename(bool $apply): void
    {
        $this->info('Renamed on their side');

        foreach (self::RENAMES as $path => $name) {
            $shelf = $this->at($path);

            if (! $shelf) {
                $this->line("  <fg=yellow>skipped</> {$path} — not here");

                continue;
            }

            /* The name only. The slug is what every link and every row in
               category_slug_history already points at, and their wording
               change is no reason to break those. */
            $this->say('rename', "{$shelf->name} → {$name}");

            if ($apply) {
                $shelf->forceFill(['name' => $name])->save();
            }
        }

        $this->newLine();
    }

    private function flatten(bool $apply): void
    {
        $this->info('Monitor makers, a level up');

        $wrapper = $this->at(self::FLATTEN);

        if (! $wrapper) {
            $this->line('  <fg=yellow>skipped</> already flat');
            $this->newLine();

            return;
        }

        $children = Category::where('parent_id', $wrapper->id)->get();
        $this->say('move', "{$children->count()} makers from “{$wrapper->name}” up to Monitor");
        $this->say('delete', "“{$wrapper->name}” — empty once its makers have moved");

        if ($apply) {
            Category::where('parent_id', $wrapper->id)
                ->update(['parent_id' => $wrapper->parent_id]);
            $wrapper->delete();
        }

        $this->newLine();
    }

    private function move(bool $apply): void
    {
        $this->info('Sitting at the wrong depth');

        foreach (self::MOVES as $path => $parentPath) {
            $shelf = $this->at($path);
            $parent = $this->at($parentPath);

            if (! $shelf || ! $parent) {
                $this->line("  <fg=yellow>skipped</> {$path} — not here");

                continue;
            }

            $this->say('move', "{$path} → under {$parent->name}");

            if ($apply) {
                $shelf->forceFill(['parent_id' => $parent->id])->save();
            }
        }

        $this->newLine();
    }

    private function add(bool $apply): void
    {
        $this->info('On their side, not ours');

        foreach (self::ADDITIONS as $parentPath => $names) {
            $parent = $this->at($parentPath);

            if (! $parent) {
                $this->line("  <fg=yellow>skipped</> {$parentPath} — not here");

                continue;
            }

            $position = (int) Category::where('parent_id', $parent->id)->max('position');

            foreach ($names as $name) {
                if ($this->at("{$parentPath}|{$name}")) {
                    continue;
                }

                $this->say('add', "{$parentPath} > {$name}");

                if ($apply) {
                    Category::create([
                        'name' => $name,
                        'slug' => SlugFactory::unique(Category::class, $name),
                        'parent_id' => $parent->id,
                        'position' => ++$position,
                        'is_active' => true,
                    ]);
                }
            }
        }

        $this->newLine();
    }

    private function remove(bool $apply): void
    {
        $this->info('Ours, not theirs');

        foreach (self::REMOVALS as $path) {
            $shelf = $this->at($path);

            if (! $shelf) {
                $this->line("  <fg=yellow>skipped</> {$path} — already gone");

                continue;
            }

            /*
             * products.category_id cascades, so deleting a shelf that still
             * holds one destroys the product. They go up to the parent first:
             * a shelf this shop does not want is no reason to lose stock.
             */
            $products = DB::table('products')->where('category_id', $shelf->id)->count();

            if ($products) {
                $parentName = Category::find($shelf->parent_id)?->name ?? 'its department';
                $this->say('rehome', "{$products} product(s) from {$path} up to {$parentName}");

                if ($apply) {
                    DB::table('products')
                        ->where('category_id', $shelf->id)
                        ->update(['category_id' => $shelf->parent_id]);
                    DB::table('category_product')
                        ->where('category_id', $shelf->id)
                        ->update(['category_id' => $shelf->parent_id]);
                }
            }

            $this->say('delete', $path);

            if ($apply) {
                $shelf->delete();
            }
        }

        $this->newLine();
    }
}
