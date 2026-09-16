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
        $labels = [
            'pending' => 'Order placed',
            'processing' => 'Being packed',
            'shipped' => 'Out for delivery',
            'delivered' => 'Delivered',
            'cancelled' => 'Cancelled',
        ];
        $label = $labels[$this->order->status] ?? ucfirst($this->order->status);
        $brand = BrandDetails::all()['name'];

        $written = MailTemplate::for('order_status', [
            'shop_name' => $brand,
            'customer_name' => $this->order->shipping_address['name'] ?? 'there',
            'order_number' => $this->order->order_number,
            // The readable label, not the database's word for it.
            'order_status' => $label,
            'order_url' => url('/track/'.$this->order->order_number),
        ]);

        if ($written) {
            return $this->subject($written['subject'])
                ->view('emails.templated', $written['data'])
                ->text('emails.templated-text', $written['data']);
        }

        // Readable rather than shouted: "Out for delivery" beats "is now SHIPPED".
        return $this->subject("Order #{$this->order->order_number} — {$label} | {$brand}")
            ->view('emails.orders.status-updated')
            ->text('emails.text.orders.status-updated');
    }
}
