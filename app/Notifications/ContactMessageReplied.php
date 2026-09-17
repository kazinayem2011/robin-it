<?php

namespace App\Notifications;

/**
 * The customer wrote back on an enquiry the shop had already answered.
 *
 * Its own notification rather than another "new message": the thread is
 * already open and assigned, and whoever answered it will want to know the
 * answer did not settle it.
 */
class ContactMessageReplied extends ShopNotification
{
    public function __construct(
        public readonly int $messageId,
        public readonly string $fromName,
        public readonly string $subject,
    ) {}

    public function payload(object $notifiable): array
    {
        return [
            'kind' => 'contact.replied',
            'title' => $this->fromName.' replied',
            'body' => $this->subject,
            'url' => '/admin/messages?message='.$this->messageId,
            'icon' => 'message',
        ];
    }
}
