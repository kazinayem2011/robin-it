<?php

namespace App\Support;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use SplObjectStorage;

/**
 * Keeps the shop's stock figures out of anything the storefront sends.
 *
 * Customers are never told how many units are on the shelf — not in a label,
 * not in a message, and not in the JSON or page props their browser receives,
 * where anyone can read them. The models keep these columns visible because
 * the admin screens need them, so every storefront exit that hands out a
 * product or an option passes it through here first. What remains is the
 * yes/no the shop is happy to say: `in_stock`, `is_preorder`, the label.
 */
final class StorefrontStock
{
    /** The columns that are counts, or that let one be worked out. */
    public const HIDDEN = ['stock_quantity', 'reorder_level', 'preorder_limit'];

    /**
     * Hide the stock figures on every product and option in $subject, however
     * deeply they sit in loaded relations (a cart's items, a wishlist row's
     * product, a product's options). Returns $subject for chaining.
     *
     * @template T
     *
     * @param  T  $subject  a model, a collection, a paginator or an array of them
     * @return T
     */
    public static function hide(mixed $subject): mixed
    {
        self::walk($subject, new SplObjectStorage);

        return $subject;
    }

    private static function walk(mixed $subject, SplObjectStorage $seen): void
    {
        if ($subject instanceof Model) {
            if ($seen->contains($subject)) {
                return;
            }

            $seen->attach($subject);

            if ($subject instanceof Product || $subject instanceof ProductVariant) {
                $subject->makeHidden(self::HIDDEN);
            }

            /*
             * An order line on a customer's page: never what the shop paid for
             * it, and never that it ran past the shelf. A genuine pre-order is
             * still marked, as `is_preorder`.
             */
            if ($subject instanceof OrderItem) {
                $subject->makeHidden(['unit_cost', 'was_preordered', 'waiting_for_stock'])
                    ->append('is_preorder');
            }

            foreach ($subject->getRelations() as $related) {
                self::walk($related, $seen);
            }

            return;
        }

        if ($subject instanceof Paginator) {
            self::walk($subject->items(), $seen);

            return;
        }

        if (is_iterable($subject)) {
            foreach ($subject as $item) {
                self::walk($item, $seen);
            }
        }
    }
}
