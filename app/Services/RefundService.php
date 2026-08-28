<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Models\Order;
use App\Models\Refund;
use App\Support\BrandDetails;
use App\Support\SmsTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Giving money back.
 *
 * Deliberately not part of returning goods. The two usually happen together
 * and sometimes do not: a damaged item may be refunded without coming back,
 * and an exchange sends goods back without any money moving. Keeping them
 * apart means neither has to pretend to be the other.
 */
class RefundService
{
    /**
     * Record a refund against an order.
     *
     * @throws StorefrontException when it would give back more than was paid
     */
    public function refund(Order $order, array $data, ?int $userId = null): Refund
    {
        $amount = round((float) $data['amount'], 2);

        return DB::transaction(function () use ($order, $data, $amount, $userId) {
            // Locked and re-read: two people refunding the same order at once
            // would otherwise each see the same "already refunded" figure and
            // both be allowed through.
            $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
            $alreadyGiven = round((float) $fresh->refunds()->sum('amount'), 2);
            $left = round((float) $fresh->total - $alreadyGiven, 2);

            if ($amount > $left) {
                throw new StorefrontException(
                    $left <= 0
                        ? 'This order has already been refunded in full.'
                        : 'That is more than is left to refund on this order — ৳'
                            .number_format($left, 2).' remains.',
                    422,
                    ApiCode::VALIDATION_ERROR,
                    ['refundable' => $left, 'already_refunded' => $alreadyGiven]
                );
            }

            $refund = $fresh->refunds()->create([
                'amount' => $amount,
                'method' => $data['method'],
                'reason' => $data['reason'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'refunded_on' => $data['refunded_on'],
                'user_id' => $userId,
            ]);

            $this->syncPaymentStatus($fresh);

            $order->setRawAttributes($fresh->fresh()->getAttributes(), true);

            $this->tellTheCustomer($fresh, $amount);

            return $refund;
        });
    }

    /**
     * Say the money is on its way.
     *
     * A refund the customer is not told about is a refund they chase, and a
     * bank transfer here can take days to appear — so the message is worth
     * sending even though the shop has already done its part.
     *
     * Best-effort: the refund is recorded and must stand whether or not a
     * gateway is reachable.
     */
    private function tellTheCustomer(Order $order, float $amount): void
    {
        try {
            app(SmsService::class)->send(
                $order->recipient_phone,
                SmsTemplates::refundIssued($order, $amount, BrandDetails::name())
            );
        } catch (\Throwable $e) {
            Log::warning("Could not send the refund SMS for {$order->order_number}: {$e->getMessage()}");
        }
    }

    /**
     * Undo a refund that should not have been recorded.
     *
     * A typo in an amount is ordinary; leaving it and adding a correcting
     * entry would make the order's history read as two refunds when there was
     * one mistake.
     */
    public function remove(Refund $refund): void
    {
        DB::transaction(function () use ($refund) {
            $order = $refund->order;
            $refund->delete();

            if ($order) {
                $this->syncPaymentStatus($order->fresh());
            }
        });
    }

    /**
     * Keep `payment_status` agreeing with the refunds recorded.
     *
     * It was the only record of a refund before, and a flag cannot express a
     * partial one. It is now a summary of the entries rather than the source
     * of truth — and it is only moved between the two refund-related states,
     * so an unpaid cash-on-delivery order that was written off is not silently
     * marked paid.
     */
    private function syncPaymentStatus(Order $order): void
    {
        $fullyRefunded = $order->isFullyRefunded();

        if ($fullyRefunded && $order->payment_status !== 'refunded') {
            $order->forceFill(['payment_status' => 'refunded'])->save();

            return;
        }

        if (! $fullyRefunded && $order->payment_status === 'refunded') {
            // A refund was removed, or a partial one no longer covers the
            // order: it is not "refunded" any more.
            $order->forceFill(['payment_status' => 'paid'])->save();
        }
    }
}
