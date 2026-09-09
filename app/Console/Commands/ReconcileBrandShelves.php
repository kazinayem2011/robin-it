<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Console\Command;

/**
 * Bring the brand shelves and the brand rows back into agreement.
 *
 * The shop lists brands as categories — "ASUS" is a shelf under Brand PC with
 * its own page — and `brands` is a separate 28 rows. Nothing kept them in step,
 * so shelves accumulated that no brand row has ever heard of. Those look right
 * in the menu and then carry no logo, show no maker on the product page, and
 * cannot be filtered or featured, because every one of those reads `brands`.
 *
 * Two halves, deliberately. Linking a shelf to a brand that already exists is
 * arithmetic and runs on --apply. Deciding that "MacBook" or "Smart" is a
 * maker is a judgement about the shop's catalogue, so those are only ever
 * listed for somebody to look at.
 */
class ReconcileBrandShelves extends Command
{
    protected $signature = 'brands:reconcile-shelves {--apply : Write the links, instead of only reporting them}';

    protected $description = 'Link brand shelves to their brand rows, and list the shelves that have none.';

    public function handle(): int
    {
        $brands = Brand::get(['id', 'name'])
            ->mapWithKeys(fn (Brand $b) => [mb_strtolower(trim($b->name)) => $b->id]);

        $shelves = Category::whereNull('brand_id')
            ->whereNotNull('parent_id')
            ->get(['id', 'name', 'parent_id']);

        $linkable = $shelves->filter(fn ($c) => $brands->has(mb_strtolower(trim($c->name))));
        $unknown = $shelves->reject(fn ($c) => $brands->has(mb_strtolower(trim($c->name))));

        $this->info("shelves with no brand: {$shelves->count()}");
        $this->info("of those, matching a brand that already exists: {$linkable->count()}");

        if ($this->option('apply')) {
            foreach ($linkable as $shelf) {
                $shelf->forceFill(['brand_id' => $brands->get(mb_strtolower(trim($shelf->name)))])->save();
            }

            CategoryService::flush();
            $this->info("linked {$linkable->count()} shelves");
        } elseif ($linkable->isNotEmpty()) {
            $this->line('  '.$linkable->take(10)->pluck('name')->implode(', '));
            $this->comment('  run with --apply to link these');
        }

        /*
         * Only ever listed. Whether a shelf name is a maker or a product line
         * is a judgement about this catalogue — "MacBook" is Apple's, "Smart"
         * and "Value-Top" could be either — and creating 386 brand rows on a
         * guess would put every one of them on the storefront's brand strip.
         */
        $names = $unknown->pluck('name')
            ->map(fn ($n) => trim($n))
            ->filter(fn ($n) => preg_match('/^[A-Za-z][A-Za-z.\-]{2,14}$/', $n))
            ->unique()
            ->sort()
            ->values();

        $this->newLine();
        $this->info("shelf names that read like a maker but have no brand row: {$names->count()}");
        $this->line(wordwrap('  '.$names->implode(', '), 100, PHP_EOL.'  '));
        $this->newLine();
        $this->comment('These are not created automatically. Set the brand on the shelf in the admin,');
        $this->comment('or use "Create <name> as a new brand" on the category form.');

        return self::SUCCESS;
    }
}
