<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * What to do about the shelves that stand for nothing.
 *
 * `brands:reconcile-shelves` links the ones whose name is already a brand and
 * then prints the rest as a wall of 451 names, which is where the job stops:
 * that list is asking someone to decide 451 times with nothing to decide on.
 * And the names are not one kind of thing. Sorted by hand they come out as
 * makers with no brand row (Hikvision, on fifteen shelves), makers already
 * present under another spelling (HUAWEI beside Huawei), and names that are
 * not makers at all — Printer, Router, SSD, Toner — sitting in the position
 * the tree reserves for a maker.
 *
 * So this gathers the evidence instead of the names. Nothing is written: the
 * output is a file to read, argue with, and act on in the admin.
 *
 *   php artisan brands:propose-shelves
 *   php artisan brands:propose-shelves --report=storage/app/brand-shelves.md
 */
class ProposeBrandShelves extends Command
{
    protected $signature = 'brands:propose-shelves
        {--report= : Write the full proposal to this file instead of the screen}';

    protected $description = 'Sort the brandless shelves into what can be linked, created, merged or is not a maker at all.';

    public function handle(): int
    {
        $brands = Brand::get(['id', 'name'])
            ->mapWithKeys(fn (Brand $b) => [$this->key($b->name) => $b->name]);

        $shelves = Category::whereNull('brand_id')
            ->whereNotNull('parent_id')
            ->get(['id', 'name', 'parent_id']);

        if ($shelves->isEmpty()) {
            $this->info('Every shelf already stands for a brand.');

            return self::SUCCESS;
        }

        $typeNames = $this->productTypeNames();
        $rootOf = $this->rootOfEveryCategory();
        $productCounts = $this->productCountsByCategory();

        /* One row per distinct spelling, carrying what is known about it. */
        $rows = $shelves
            ->groupBy(fn (Category $c) => trim($c->name))
            ->map(function (Collection $group, string $name) use ($rootOf, $productCounts) {
                $ids = $group->pluck('id');

                return [
                    'name' => $name,
                    'shelves' => $group->count(),
                    'departments' => $ids
                        ->map(fn ($id) => $rootOf[$id] ?? null)
                        ->filter()
                        ->unique()
                        ->values(),
                    'products' => $ids->sum(fn ($id) => $productCounts[$id] ?? 0),
                ];
            })
            ->values();

        /*
         * Four answers, in the order they should be acted on: the ones that
         * need no decision first, the 451-name judgement last.
         */
        $link = $rows->filter(fn ($r) => $brands->has($this->key($r['name'])));
        $rest = $rows->reject(fn ($r) => $brands->has($this->key($r['name'])));

        $notMakers = $rest->filter(fn ($r) => $typeNames->contains($this->key($r['name'])));
        $makers = $rest->reject(fn ($r) => $typeNames->contains($this->key($r['name'])));

        /*
         * Spellings of one maker. Grouped on a key that ignores case, spaces
         * and hyphens, so MikroTik/Mikrotik and T-Wolf/T-WOLF land together —
         * creating a brand row for each would put the same maker on the
         * storefront's strip twice.
         */
        $spellings = $makers->groupBy(fn ($r) => $this->key($r['name']))->filter(fn ($g) => $g->count() > 1);
        $spelled = $spellings->flatten(1)->pluck('name');
        $create = $makers->reject(fn ($r) => $spelled->contains($r['name']));

        $report = $this->render($link, $create, $spellings, $notMakers, $brands);

        if ($path = $this->option('report')) {
            File::ensureDirectoryExists(dirname(base_path($path)));
            File::put(base_path($path), $report);
            $this->info("Written to {$path}");
        } else {
            $this->line($report);
        }

        $this->newLine();
        $this->comment('Nothing has been changed. Linking the first group is `brands:reconcile-shelves --apply`;');
        $this->comment('the rest are decisions, made on the category form in the admin.');

        return self::SUCCESS;
    }

    /** Case, spacing and hyphens are spelling, not identity. */
    private function key(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($name)));
    }

    /**
     * Names the tree uses for a kind of product rather than a maker of one.
     *
     * What separates them is what hangs beneath. `Printer` has seven makers
     * under it — HP, Epson, Brother — and `Keyboard` twenty-eight; a maker's
     * shelf is a leaf. It holds across the whole tree: of the 112 names with
     * children, not one is a brand the shop already knows, and every brand it
     * does know is childless wherever it appears.
     *
     * The obvious test — the name is also a level 1 or 2 shelf — reads this
     * catalogue backwards, because a maker sitting at level 2 is the design
     * here, not an accident. It called Xiaomi, OnePlus, Realme and HUAWEI
     * product types.
     */
    private function productTypeNames(): Collection
    {
        return Category::whereNotNull('parent_id')
            ->whereHas('children')
            ->pluck('name')
            ->map(fn ($n) => $this->key($n))
            ->unique();
    }

    /** Every category's top-level department, for saying where a name appears. */
    private function rootOfEveryCategory(): array
    {
        $parents = Category::pluck('parent_id', 'id')->all();
        $names = Category::pluck('name', 'id')->all();
        $root = [];

        foreach ($parents as $id => $parent) {
            $walk = $id;

            /* Depth-capped: a cycle here would otherwise hang the command. */
            for ($step = 0; $step < 10 && ($parents[$walk] ?? null) !== null; $step++) {
                $walk = $parents[$walk];
            }

            $root[$id] = $names[$walk] ?? null;
        }

        return $root;
    }

    private function productCountsByCategory(): array
    {
        return DB::table('category_product')
            ->select('category_id', DB::raw('count(*) as total'))
            ->groupBy('category_id')
            ->pluck('total', 'category_id')
            ->all();
    }

    private function render(
        Collection $link,
        Collection $create,
        Collection $spellings,
        Collection $notMakers,
        Collection $brands,
    ): string {
        $out = [];
        $out[] = '# Shelves that stand for no brand';
        $out[] = '';
        $out[] = 'Generated by `php artisan brands:propose-shelves`. Nothing here has been applied.';
        $out[] = '';
        $out[] = sprintf(
            '%d shelves carry no brand, across %d spellings. A shelf with no brand shows no logo, '
            .'names no maker on the product page, and cannot be filtered or featured.',
            $link->sum('shelves') + $create->sum('shelves') + $spellings->flatten(1)->sum('shelves') + $notMakers->sum('shelves'),
            $link->count() + $create->count() + $spellings->flatten(1)->count() + $notMakers->count(),
        );
        $out[] = '';

        $out[] = '## 1. Link to a brand that already exists';
        $out[] = '';
        $out[] = 'No decision needed — the brand row is already there. `php artisan brands:reconcile-shelves --apply`';
        $out[] = '';
        $out[] = $this->markdownTable($link, fn ($r) => 'link to **'.$brands->get($this->key($r['name'])).'**');

        $out[] = '## 2. Makers with no brand row';
        $out[] = '';
        $out[] = 'Each of these needs a brand created, then the shelves pointed at it. Ordered by how '
            .'much of the shop is waiting on it. A name on shelves in several departments is almost '
            .'certainly a maker; one on a single shelf is worth a look before creating anything.';
        $out[] = '';
        $out[] = $this->markdownTable($create->sortByDesc('shelves')->values(), fn ($r) => 'create and link');

        $out[] = '## 3. One maker, spelled two ways';
        $out[] = '';
        $out[] = 'Create the brand once, under the spelling you want shoppers to read, and point every '
            .'spelling at it. Creating one per spelling puts the same maker on the brand strip twice.';
        $out[] = '';

        if ($spellings->isEmpty()) {
            $out[] = '_None._';
            $out[] = '';
        } else {
            foreach ($spellings as $group) {
                $names = $group->map(fn ($r) => sprintf('`%s` (%d shelves)', $r['name'], $r['shelves']));
                $out[] = '- '.$names->implode(' · ');
            }
            $out[] = '';
        }

        $out[] = '## 4. Not makers — nothing to do';
        $out[] = '';
        $out[] = 'Each of these has makers beneath it, which is what makes it a kind of product rather '
            .'than the maker of one. They are right to have no brand, and are listed only so the count '
            .'at the top adds up and nobody sets out to give them one.';
        $out[] = '';
        $out[] = $this->markdownTable($notMakers->sortByDesc('shelves')->values(), fn () => 'correct as it is');

        return implode(PHP_EOL, $out);
    }

    /* Named for the format, because Command::table() is the framework's. */
    private function markdownTable(Collection $rows, callable $action): string
    {
        if ($rows->isEmpty()) {
            return '_None._'.PHP_EOL.PHP_EOL;
        }

        $lines = ['| Name | Shelves | Departments | Products | Suggested |', '| --- | ---: | --- | ---: | --- |'];

        foreach ($rows as $row) {
            $departments = $row['departments']->take(3)->implode(', ')
                .($row['departments']->count() > 3 ? ', +'.($row['departments']->count() - 3) : '');

            $lines[] = sprintf(
                '| %s | %d | %s | %d | %s |',
                $row['name'],
                $row['shelves'],
                $departments ?: '—',
                $row['products'],
                $action($row),
            );
        }

        return implode(PHP_EOL, $lines).PHP_EOL.PHP_EOL;
    }
}
