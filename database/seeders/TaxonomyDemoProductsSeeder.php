<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * One placeholder product in every otherwise-empty leaf category, so the
 * category tree can actually be seen.
 *
 * ## Why this is needed at all
 *
 * CategoryService filters the mega menu to categories that hold products —
 * deliberately, so navigation never leads to "No products found". The
 * consequence is that TaxonomySeeder on its own changes nothing visible: seven
 * of seventeen top-level entries appear, and the ten new ones do not. This
 * fills the tree so the structure can be reviewed.
 *
 * ## These are placeholders and are meant to be deleted
 *
 * Every row is named "Sample <category>" and slugged `sample-<category>`, so
 * they are trivial to find and remove once real stock is entered:
 *
 *     Product::where('slug', 'like', 'sample-%')->delete();
 *
 * They are deliberately obvious. A plausible-looking invented product is worse
 * than an openly fake one: nobody deletes what they mistake for real data, and
 * a shop that quietly ships "Sample HDMI Cable" to a customer has a bigger
 * problem than an untidy catalogue.
 *
 * Stock is zero. The mega menu only asks that a product be active, not that it
 * be in stock, so a placeholder never claims the shop holds something it does
 * not — and never lets anyone buy one.
 */
class TaxonomyDemoProductsSeeder extends Seeder
{
    public function run(): void
    {
        $leaves = Category::doesntHave('children')
            ->withCount('products')
            ->get()
            ->where('products_count', 0);

        if ($leaves->isEmpty()) {
            $this->command?->info('Every leaf category already holds a product. Nothing to do.');

            return;
        }

        $created = 0;

        foreach ($leaves as $leaf) {
            $slug = "sample-{$leaf->slug}";

            if (Product::where('slug', $slug)->exists()) {
                continue;
            }

            $product = Product::create([
                'category_id' => $leaf->id,
                'brand_id' => null,
                'name' => "Sample {$leaf->name}",
                'slug' => $slug,
                'price' => $this->indicativePrice($leaf->slug),
                'discount_price' => null,
                // Zero: a placeholder must never look purchasable.
                'stock_quantity' => 0,
                'short_description' => "Placeholder so the {$leaf->name} category appears in the menu. Replace with real stock.",
                'description' => null,
                'is_featured' => false,
                'is_active' => true,
            ]);

            $product->images()->create([
                'image_path' => '/images/product-placeholder.svg',
                'is_primary' => true,
            ]);

            $created++;
        }

        $this->command?->info("Placeholders created: {$created}. Remove them with: Product::where('slug', 'like', 'sample-%')->delete()");
    }

    /**
     * A stable, plausible-looking figure so a category page is not a column of
     * identical numbers. Derived from the slug rather than randomised, so
     * re-running never quietly changes a price.
     */
    private function indicativePrice(string $slug): float
    {
        $steps = crc32($slug) % 40;

        return 500.0 + ($steps * 250);
    }
}
