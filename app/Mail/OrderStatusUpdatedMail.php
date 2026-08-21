<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Queued so checkout does not wait on the mail server. Requires a queue worker
 * (`php artisan queue:work`) to be running — without one, mail is written to the
 * jobs table and never sent.
 */
class OrderStatusUpdatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Order $order;

    /**
     * Create a new message instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order->loadMissing(['user']);
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $statusUpper = strtoupper($this->order->status);

        return $this->subject("Your Order #{$this->order->order_number} is now {$statusUpper} — Robin IT")
            ->view('emails.orders.status-updated');
    }
}
