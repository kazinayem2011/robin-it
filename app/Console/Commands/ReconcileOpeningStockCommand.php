<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Account for stock that no movement explains.
 *
 * Every unit in the shop is supposed to be the sum of its ledger: a purchase,
 * a sale, a return, an audited adjustment, or an opening balance entered when
 * the product was first created. The seeders did not honour that — several
 * wrote `stock_quantity` straight onto the row — so the stock screen showed
 * quantities against products that had never been bought, with an empty
 * History behind them and nothing in the per-store table.
 *
 * This settles the difference one way or the other:
 *
 *   --as-opening (default)  the number is real stock that predates the ledger,
 *                           so record it as an opening balance
 *   --zero                  the number was never real, so clear it
 *
 * Either way the shop stops holding a figure it cannot account for. Only rows
 * with no movements at all are touched, so anything with a genuine history is
 * left exactly as it is and a second run does nothing.
 */
class ReconcileOpeningStockCommand extends Command
{
    protected $signature = 'stock:reconcile-opening
                            {--zero : Clear the unexplained stock instead of recording it as an opening balance}
                            {--dry-run : List what would change and stop}';

    protected $description = 'Give a ledger entry to stock that no movement explains, or clear it';

    public function __construct(private readonly StockService $stock)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $zero = (bool) $this->option('zero');
        $dryRun = (bool) $this->option('dry-run');

        $products = Product::where('stock_quantity', '>', 0)
            ->whereDoesntHave('stockMovements')
            ->orderBy('id')
            ->get(['id', 'name', 'stock_quantity']);

        $variants = ProductVariant::where('stock_quantity', '>', 0)
            ->whereDoesntHave('stockMovements')
            ->with('product:id,name')
            ->orderBy('id')
            ->get();

        if ($products->isEmpty() && $variants->isEmpty()) {
            $this->info('Every quantity in the shop is already explained by a movement.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($products as $product) {
            $rows[] = ['Product', $product->id, $product->name, $product->stock_quantity];
        }

        foreach ($variants as $variant) {
            $rows[] = ['Option', $variant->id, $variant->product?->name ?? '—', $variant->stock_quantity];
        }

        $this->table(['Kind', 'ID', 'Name', 'Quantity'], $rows);

        $total = $products->sum('stock_quantity') + $variants->sum('stock_quantity');

        $this->line($zero
            ? "Would clear {$total} unit(s) across ".count($rows).' row(s).'
            : "Would record {$total} unit(s) across ".count($rows).' row(s) as opening balances.');

        if ($dryRun) {
            return self::SUCCESS;
        }

        // Nothing here is reversible by hand at scale, and it runs against
        // production, so an unattended invocation has to pass --no-interaction
        // deliberately.
        if ($this->input->isInteractive() && ! $this->confirm('Apply this?', true)) {
            $this->warn('Nothing changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($products, $variants, $zero) {
            foreach ($products as $product) {
                $this->settle($product->id, null, (int) $product->stock_quantity, $zero);
            }

            foreach ($variants as $variant) {
                $this->settle($variant->product_id, $variant->id, (int) $variant->stock_quantity, $zero);
            }
        });

        $this->info($zero
            ? 'Cleared. The stock screen now shows nothing the ledger cannot account for.'
            : 'Recorded. Each quantity now has an opening balance behind it in History.');

        if (! $zero && ! Store::onlineFulfilment()) {
            $this->warn('No store fulfils online orders, so the per-branch table was left alone.');
        }

        return self::SUCCESS;
    }

    private function settle(int $productId, ?int $variantId, int $quantity, bool $zero): void
    {
        if ($zero) {
            $variantId
                ? ProductVariant::where('id', $variantId)->update(['stock_quantity' => 0])
                : Product::where('id', $productId)->update(['stock_quantity' => 0]);

            ProductStock::where('product_id', $productId)
                ->where('product_variant_id', $variantId)
                ->update(['quantity' => 0]);

            return;
        }

        // Through the service, so this lands identically to a product entered
        // with an opening quantity through the admin form — same movement type,
        // same branch row.
        $this->stock->recordOpeningBalance(
            Product::findOrFail($productId),
            $variantId ? ProductVariant::find($variantId) : null,
            $quantity,
            null,
            'Opening balance recorded for stock that predates the ledger',
        );
    }
}
