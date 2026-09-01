<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give every product the brand it already tells you it is.
 *
 * products.brand_id has existed since the first migration, with a foreign key
 * and an (is_active, brand_id) index put there for the shop's own brand
 * filter. Nothing ever wrote to it: the catalogue was seeded without brands,
 * and the admin form's brand field spent a while defaulting to the first row
 * in the table, so it was safer to leave alone than to set.
 *
 * The result is a Brand facet that renders correctly and is always empty,
 * because it is built from the products in scope and none of them has one.
 *
 * The information is in the catalogue already, in two places: the category a
 * product is filed under, which for this shop is very often the manufacturer —
 * Hikvision, Dahua, Fantech, Canon — and the product's own name, which nearly
 * always opens with the maker. Both are read here.
 *
 * Only brands the shop has already named are used, and this is the whole
 * design of the thing. A third rule was tried — treat the category as a maker
 * whenever the product's own name repeats it — and on a dry run it wanted to
 * create 575 brands, among them "AI PC", "Ryzen PC", "MacBook" and "Smart".
 * The catalogue is mostly seeded rows called "Sample <category>", so every
 * category matched its own products and every product type became a
 * manufacturer. A brand table full of those is worse than an empty column: the
 * filter would fill with nonsense a shopkeeper then has to clear out by hand.
 *
 * So what cannot be named from the shop's own list of brands is left alone,
 * and is set in the admin when the real product is entered.
 *
 * Nothing is matched on a partial word: "PowerColor" must not become Power and
 * "Fantech" must not become Fan.
 */
class LinkProductBrandsCommand extends Command
{
    protected $signature = 'catalogue:link-brands
                            {--dry-run : Show what would change and write nothing}';

    protected $description = 'Fill in products.brand_id from the category a product sits under and the name it carries';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        /** @var array<string, Brand> $brands keyed by lowercase name */
        $brands = Brand::all()->keyBy(fn (Brand $b) => mb_strtolower($b->name))->all();
        $categories = Category::pluck('name', 'id')->all();

        $products = Product::whereNull('brand_id')
            ->orderBy('id')
            ->get(['id', 'name', 'category_id']);

        if ($products->isEmpty()) {
            $this->info('Every product already has a brand.');

            return self::SUCCESS;
        }

        $byBrand = [];      // brand name => count
        $assignments = [];  // product id => brand id
        $unmatched = 0;

        foreach ($products as $product) {
            $category = $categories[$product->category_id] ?? null;
            $brand = $this->resolve($product->name, $category, $brands);

            if ($brand === null) {
                $unmatched++;

                continue;
            }

            $assignments[$product->id] = $brand->id;
            $byBrand[$brand->name] = ($byBrand[$brand->name] ?? 0) + 1;
        }

        arsort($byBrand);

        $this->line(sprintf(
            '%d product(s) without a brand: %d can be named from the %d brand(s) the shop has, %d cannot.',
            $products->count(),
            count($assignments),
            count($brands),
            $unmatched
        ));

        if ($unmatched > 0) {
            $this->line('The rest carry no brand this shop knows; they are left for the admin.');
        }

        $this->table(
            ['Brand', 'Products'],
            collect($byBrand)->take(20)->map(fn ($n, $b) => [$b, $n])->all()
        );

        if ($dryRun) {
            $this->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        if ($assignments === []) {
            $this->info('Nothing to link: no product names a brand this shop knows.');

            return self::SUCCESS;
        }

        if ($this->input->isInteractive() && ! $this->confirm('Apply this?', true)) {
            $this->warn('Nothing changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($assignments) {
            $byBrandId = [];

            foreach ($assignments as $productId => $brandId) {
                $byBrandId[$brandId][] = $productId;
            }

            // One statement per brand rather than per product: this runs over
            // the whole catalogue.
            foreach ($byBrandId as $brandId => $productIds) {
                Product::whereIn('id', $productIds)->update(['brand_id' => $brandId]);
            }
        });

        $this->info(sprintf('Linked %d product(s) to a brand.', count($assignments)));

        return self::SUCCESS;
    }

    /**
     * The brand a product belongs to, or null when the shop's own list of
     * brands does not cover it.
     *
     * @param  array<string, Brand>  $brands  keyed by lowercase name
     */
    private function resolve(string $productName, ?string $category, array $brands): ?Brand
    {
        // The category is a brand the shop already knows: Monitor > ASUS.
        if ($category !== null && isset($brands[mb_strtolower($category)])) {
            return $brands[mb_strtolower($category)];
        }

        // Otherwise the name says it: "Logitech MX Master 3S Wireless Mouse".
        foreach ($brands as $brand) {
            if ($this->namesWord($productName, $brand->name)) {
                return $brand;
            }
        }

        return null;
    }

    /** Whole words only: "Fantech" is not "Fan", "PowerColor" is not "Power". */
    private function namesWord(string $haystack, string $needle): bool
    {
        if (trim($needle) === '') {
            return false;
        }

        return (bool) preg_match(
            '/\b'.preg_quote(trim($needle), '/').'\b/iu',
            $haystack
        );
    }
}
