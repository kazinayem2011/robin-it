<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Services\StockService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Settle stock that nobody bought.
 *
 * Every unit in the shop is supposed to be the sum of its ledger: a purchase, a
 * sale, a return, an audited adjustment, or an opening balance entered when the
 * product was first created. Several seeders did not honour that — they wrote
 * `stock_quantity` straight onto the row — so a shop that had bought nothing
 * showed quantities on the stock screen with an empty History behind them.
 *
 * "Unexplained" therefore means *nothing but an opening balance*: no movement,
 * or only the seeded kind. That deliberately includes what an earlier run of
 * this command wrote, because an opening balance is a claim about stock, not
 * evidence of it — recording one against seed data makes the fiction look more
 * convincing, not less, and the first version of this command could not reach
 * its own output to undo it.
 *
 * Anything with a purchase, sale, transfer or adjustment against it has a real
 * history and is never touched.
 *
 *   (default)  the stock is real and predates the ledger — record the opening
 *              balance and place it at the fulfilling branch
 *   --zero     the stock was never real — clear the balance and delete the
 *              opening entries that claimed it
 */
class ReconcileOpeningStockCommand extends Command
{
    protected $signature = 'stock:reconcile-opening
                            {--zero : Clear the unexplained stock instead of recording it as an opening balance}
                            {--dry-run : List what would change and stop}';

    protected $description = 'Settle stock no purchase explains — record it as an opening balance, or clear it';

    public function __construct(private readonly StockService $stock)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $zero = (bool) $this->option('zero');
        $dryRun = (bool) $this->option('dry-run');

        $products = Product::where('stock_quantity', '>', 0)
            ->where($this->unexplained(...))
            ->orderBy('id')
            ->get(['id', 'name', 'stock_quantity']);

        $variants = ProductVariant::where('stock_quantity', '>', 0)
            ->where($this->unexplained(...))
            ->with('product:id,name')
            ->orderBy('id')
            ->get();

        if ($products->isEmpty() && $variants->isEmpty()) {
            $this->info('Nothing to settle: every quantity in the shop has a purchase, sale or adjustment behind it.');

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
            ? "Would clear {$total} unit(s) across ".count($rows).' row(s), and delete the opening entries claiming them.'
            : "Would record {$total} unit(s) across ".count($rows).' row(s) as opening balances.');

        if ($dryRun) {
            return self::SUCCESS;
        }

        // --zero throws quantities away, and this runs against production, so
        // an unattended invocation has to pass --no-interaction deliberately.
        if ($this->input->isInteractive() && ! $this->confirm('Apply this?', ! $zero)) {
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
            ? 'Cleared. The stock screen now shows only what the shop actually took delivery of.'
            : 'Recorded. Each quantity now has an opening balance behind it in History.');

        if (! $zero && ! Store::onlineFulfilment()) {
            $this->warn('No store fulfils online orders, so the per-branch table was left alone.');
        }

        return self::SUCCESS;
    }

    /**
     * Stock with no history but a claim of one: either no movements at all, or
     * only opening entries. One `whereDoesntHave` covers both.
     */
    private function unexplained(Builder $query): void
    {
        $query->whereDoesntHave(
            'stockMovements',
            fn (Builder $movements) => $movements->where('type', '!=', StockMovement::OPENING)
        );
    }

    private function settle(int $productId, ?int $variantId, int $quantity, bool $zero): void
    {
        if (! $zero) {
            // A second opening balance on the same unit would read as a
            // delivery nobody made, so a row already carrying one is left be.
            $alreadyOpened = StockMovement::where('product_id', $productId)
                ->where('product_variant_id', $variantId)
                ->where('type', StockMovement::OPENING)
                ->exists();

            if ($alreadyOpened) {
                return;
            }

            // Through the service, so this lands identically to a product
            // entered with an opening quantity through the admin form — same
            // movement type, same branch row.
            $this->stock->recordOpeningBalance(
                Product::findOrFail($productId),
                $variantId ? ProductVariant::find($variantId) : null,
                $quantity,
                null,
                'Opening balance recorded for stock that predates the ledger',
            );

            return;
        }

        $variantId
            ? ProductVariant::where('id', $variantId)->update(['stock_quantity' => 0])
            : Product::where('id', $productId)->update(['stock_quantity' => 0]);

        // Deleted rather than set to zero: a branch that holds none of
        // something has no holding, and a row saying 0 shows up on the product
        // panel as "Held at: Flagship Showroom: 0".
        ProductStock::where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->delete();

        /*
         * The opening entries go too. They are the claim being withdrawn — a
         * ledger reading "opening +5" against a balance of 0, with nothing in
         * between, does not add up, and leaving it would put a delivery nobody
         * made into the stock history for good.
         *
         * Safe because of the selection above: this row has no purchase, sale,
         * transfer or adjustment against it, so an opening is the only kind of
         * movement that can be here.
         */
        StockMovement::where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->where('type', StockMovement::OPENING)
            ->delete();
    }
}
