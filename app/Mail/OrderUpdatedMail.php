<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\BrandDetails;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The shop changed what is on an order after it was placed.
 *
 * A customer rings to add a part, or one is out of stock and comes off, and the
 * bill moves. The confirmation they are holding no longer says what arrives or
 * what to pay the rider, so they are sent what the order holds now.
 *
 * Designed rather than written from a template, like the confirmation: it is a
 * receipt — the lines, the new total, where it is going — not a paragraph.
 */
class OrderUpdatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Order $order;

    /**
     * @param  float  $totalBefore  what the customer was told to pay until now
     */
    public function __construct(Order $order, public float $totalBefore)
    {
        $this->order = $order->loadMissing(['user', 'items']);
    }

    public function build()
    {
        $brand = BrandDetails::all()['name'];

        return $this->subject("Order #{$this->order->order_number} was updated | {$brand}")
            ->view('emails.orders.updated')
            ->text('emails.text.orders.updated');
    }
}
