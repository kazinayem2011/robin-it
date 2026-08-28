<?php

namespace App\Services;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Taking money against an order, in whole or in part.
 *
 * An order carried a payment_status of unpaid or paid and nothing else, so a
 * customer who put ৳20,000 down on a ৳2,45,000 build was recorded exactly like
 * one who had paid nothing.
 */
class OrderPaymentService
{
    /**
     * Record money received.
     *
     * @param  float  $amount  positive for a payment, negative to correct one
     *                         taken in error
     */
    public function record(
        Order $order,
        User $staff,
        float $amount,
        string $method,
        ?string $reference = null,
        ?string $note = null,
        ?string $receivedOn = null,
    ): OrderPayment {
        $amount = round($amount, 2);

        if ($amount === 0.0) {
            throw new StorefrontException(
                'Enter how much was received.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        if (! array_key_exists($method, OrderPayment::METHODS)) {
            throw new StorefrontException(
                'Choose how the money was received.',
                422,
                ApiCode::VALIDATION_ERROR
            );
        }

        return DB::transaction(function () use ($order, $staff, $amount, $method, $reference, $note, $receivedOn) {
            /*
             * Locked and re-read inside the transaction. Two people at two
             * counters taking the last of what is owed would otherwise both
             * see the same amount outstanding and both be allowed to take it.
             */
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $due = $order->amount_due;

            if ($amount > 0 && $amount > $due) {
                throw new StorefrontException(
                    $due <= 0
                        ? 'This order is already paid in full.'
                        : 'That is more than the '.number_format($due, 2).' still owed on this order.',
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }

            /*
             * A correction cannot take the total received below zero: the shop
             * would be recording that it had handed money over, which is a
             * refund and belongs in the refunds ledger where the reason and
             * the method are asked for.
             */
            if ($amount < 0 && $order->amount_paid + $amount < 0) {
                throw new StorefrontException(
                    'A correction cannot take the amount received below zero. '
                        .'To give money back, record a refund.',
                    422,
                    ApiCode::VALIDATION_ERROR
                );
            }

            $payment = OrderPayment::create([
                'order_id' => $order->id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'note' => $note,
                'user_id' => $staff->id,
                'received_by_name' => $staff->name,
                'received_on' => $receivedOn ?: now()->toDateString(),
            ]);

            $this->syncStatus($order->fresh());

            return $payment;
        });
    }

    /**
     * Keep the old flag in step with the amounts.
     *
     * payment_status is read all over the app and by anything reporting on
     * orders. It is no longer the source of truth — the payment rows are — but
     * it must not contradict them.
     */
    public function syncStatus(Order $order): void
    {
        // Refunded is a statement about money given back and outranks how much
        // is currently outstanding; leaving it alone keeps that history.
        if ($order->payment_status === 'refunded') {
            return;
        }

        $order->forceFill(['payment_status' => $order->payment_state])->save();
    }
}
