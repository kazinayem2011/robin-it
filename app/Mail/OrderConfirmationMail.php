<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\BrandDetails;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Queued so checkout does not wait on the mail server. Requires a queue worker
 * (`php artisan queue:work`) to be running — without one, mail is written to the
 * jobs table and never sent.
 */
class OrderConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Order $order;

    /**
     * Create a new message instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order->loadMissing(['user', 'items.product']);
    }

    /**
     * Build the message.
     */
    public function build()
    {
        // text() adds the plain-text part: multipart/alternative is better for
        // deliverability and is what text-only clients fall back to.
        return $this->subject("Order Confirmation #{$this->order->order_number} — ".BrandDetails::all()['name'])
            ->view('emails.orders.confirmation')
            ->text('emails.text.orders.confirmation');
    }
}
