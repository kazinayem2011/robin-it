<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\CategoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Take the seeded "Sample …" products out of the catalogue.
 *
 * The shop was seeded with 1,265 placeholder products, every one named
 * "Sample <category>", so the storefront had something to show before the
 * real catalogue was entered. The real products are being entered now, and
 * the placeholders sit beside them in every listing, search and filter.
 *
 * The admin has no delete for a product, on purpose: a product that was sold
 * or stocked is part of the shop's records. So this keeps to the same rule.
 * A sample nothing ever happened to — no order, purchase, delivery, stock
 * movement or serial — is deleted outright, with its photos, specs and
 * options. One with any history is hidden (set to draft) instead, so the
 * orders and ledgers that name it still read.
 *
 * Only products whose name begins "Sample " are touched. A dry run unless
 * --force is given.
 */
class RemoveSampleProductsCommand extends Command
{
    protected $signature = 'catalogue:remove-samples
                            {--force : Delete and hide for real (without it, only show what would happen)}';

    protected $description = 'Delete the seeded "Sample …" products, hiding any that have order or stock history';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $samples = Product::where('name', 'like', 'Sample %')->orderBy('id')->get(['id', 'name', 'is_active']);

        if ($samples->isEmpty()) {
            $this->info('No "Sample …" products left. Nothing to do.');

            return self::SUCCESS;
        }

        $ids = $samples->pluck('id')->all();

        // Anything that happened to a product is a reason to keep its row.
        $history = collect()
            ->merge(DB::table('order_items')->whereIn('product_id', $ids)->pluck('product_id'))
            ->merge(DB::table('purchase_order_items')->whereIn('product_id', $ids)->pluck('product_id'))
            ->merge(DB::table('stock_receipt_items')->whereIn('product_id', $ids)->pluck('product_id'))
            ->merge(DB::table('stock_movements')->whereIn('product_id', $ids)->pluck('product_id'))
            ->merge(DB::table('product_serials')->whereIn('product_id', $ids)->pluck('product_id'))
            ->unique()
            ->flip();

        $toHide = $samples->filter(fn ($p) => $history->has($p->id));
        $toDelete = $samples->reject(fn ($p) => $history->has($p->id));

        $this->line(sprintf(
            '%d "Sample …" products: %d to delete, %d to hide (they have order or stock history).',
            $samples->count(), $toDelete->count(), $toHide->count(),
        ));

        foreach ($toHide as $p) {
            $this->line("  hide   #{$p->id} {$p->name}".($p->is_active ? '' : ' (already a draft)'));
        }

        $kept = Product::where('name', 'not like', 'Sample %')->orderBy('id')->get(['id', 'name']);
        $this->line("Not touched — {$kept->count()} other product(s):");
        foreach ($kept as $p) {
            $this->line("  keep   #{$p->id} {$p->name}");
        }

        if (! $force) {
            $this->warn('Dry run: nothing was changed. Run again with --force to do it.');

            return self::SUCCESS;
        }

        $deleteIds = $toDelete->pluck('id')->all();

        // Photos belonging only to the products being deleted.
        $paths = ProductImage::whereIn('product_id', $deleteIds)->pluck('image_path')
            ->merge(ProductVariant::whereIn('product_id', $deleteIds)->whereNotNull('image_url')->pluck('image_url'))
            ->filter()
            ->unique();

        DB::transaction(function () use ($deleteIds, $toHide) {
            foreach (array_chunk($deleteIds, 200) as $chunk) {
                // Everything else that names a product goes with it (the
                // database cascades); order lines are not touched because
                // none of these has any.
                Product::whereIn('id', $chunk)->delete();
            }

            if ($toHide->isNotEmpty()) {
                Product::whereIn('id', $toHide->pluck('id'))->update(['is_active' => false, 'is_featured' => false]);
            }
        });

        // A photo still used by a product that stays is left where it is.
        $stillUsed = ProductImage::whereIn('image_path', $paths)->pluck('image_path')
            ->merge(ProductVariant::whereIn('image_url', $paths)->pluck('image_url'))
            ->flip();
        $removedFiles = 0;

        foreach ($paths as $path) {
            if ($stillUsed->has($path) || ! str_starts_with((string) $path, '/storage/')) {
                continue;
            }

            $relative = ltrim(substr((string) $path, strlen('/storage/')), '/');

            if (Storage::disk('public')->exists($relative)) {
                Storage::disk('public')->delete($relative);
                $removedFiles++;
            }
        }

        // Menus, listings and the search vocabulary are cached, and a bulk
        // delete fires no model events to clear them.
        CategoryService::flush();
        Cache::forget('search.vocabulary');

        $this->info(sprintf(
            'Deleted %d sample products (%d photo files), hid %d. %d products remain.',
            count($deleteIds), $removedFiles, $toHide->count(), Product::count(),
        ));

        return self::SUCCESS;
    }
}
