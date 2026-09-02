<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What the shop can be filtered by, and how much of it is answered.
 *
 * Read-only. Seeding the questions and answering them are separate jobs — the
 * seeder defines what a shelf may ask and never touches a product — so after a
 * deploy the useful question is not "did the seeder run" but "does the shop
 * now filter", and those have different answers. A shelf with its questions
 * defined and nothing answering them shows an empty sidebar, because a facet
 * offers only the answers products actually give.
 *
 *   php artisan catalogue:filters
 */
class CatalogueFiltersCommand extends Command
{
    protected $signature = 'catalogue:filters';

    protected $description = 'Show which shelves can be filtered, and how many products answer';

    public function handle(): int
    {
        $attributes = Attribute::with('values', 'categories')->get();

        if ($attributes->isEmpty()) {
            $this->warn('No questions are defined. Run: php artisan db:seed --class=CatalogueAttributeSeeder');

            return self::SUCCESS;
        }

        $rows = [];

        foreach (Category::whereHas('attributes')->orderBy('name')->get() as $category) {
            $shelfAttributes = $attributes->filter(
                fn (Attribute $a) => $a->categories->contains('id', $category->id)
            );

            $valueIds = $shelfAttributes->flatMap->values->pluck('id');

            // Products anywhere at or below this shelf that give any answer.
            $answering = Product::query()
                ->whereHas('attributeValues', fn ($q) => $q->whereIn('attribute_values.id', $valueIds))
                ->count();

            $rows[] = [
                $category->name,
                $category->slug,
                $shelfAttributes->count(),
                $shelfAttributes->flatMap->values->count(),
                $answering ?: '— none yet',
            ];
        }

        $this->table(['Shelf', 'Slug', 'Questions', 'Answers', 'Products answering'], $rows);

        $orphans = $attributes->filter(fn (Attribute $a) => $a->categories->isEmpty());

        if ($orphans->isNotEmpty()) {
            $this->warn(
                $orphans->count().' question(s) are attached to no shelf and can never appear: '
                .$orphans->pluck('slug')->implode(', ')
            );
        }

        $this->newLine();
        $this->line(sprintf(
            '%d questions, %d answers, %d product link(s).',
            $attributes->count(),
            $attributes->flatMap->values->count(),
            DB::table('attribute_value_product')->count(),
        ));

        return self::SUCCESS;
    }
}
