<?php

namespace App\Notifications;

use App\Models\ContactMessage;

/**
 * The shop answered this customer's enquiry.
 *
 * The answer is emailed as well, and always was. This is for the customer who
 * gave no email address, and for anybody who reads the shop's bell sooner than
 * their inbox — and it leads to the thread, where they can write back.
 */
class ContactAnswered extends ShopNotification
{
    public function __construct(public readonly ContactMessage $message) {}

    public function payload(object $notifiable): array
    {
        return [
            'kind' => 'contact.answered',
            'title' => 'We replied to your message',
            'body' => $this->message->subject,
            'url' => '/dashboard/messages?message='.$this->message->id,
            'icon' => 'message',
        ];
    }
}
