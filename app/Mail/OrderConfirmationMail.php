<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\BrandDetails;
use App\Support\MailTemplate;
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
        $written = MailTemplate::for('order_placed', $this->values());

        if ($written) {
            return $this->subject($written['subject'])
                ->view('emails.templated', $written['data'])
                ->text('emails.templated-text', $written['data']);
        }

        return $this->subject("Order Confirmation #{$this->order->order_number} — ".BrandDetails::all()['name'])
            ->view('emails.orders.confirmation')
            ->text('emails.text.orders.confirmation');
    }

    /** @return array<string, string> */
    private function values(): array
    {
        return [
            'shop_name' => BrandDetails::all()['name'],
            'customer_name' => $this->order->shipping_address['name'] ?? 'there',
            'order_number' => $this->order->order_number,
            'order_total' => 'Tk '.number_format((float) $this->order->total, 0),
            'order_items' => MailTemplate::orderItems($this->order),
            'order_url' => url('/track/'.$this->order->order_number),
        ];
    }
}
