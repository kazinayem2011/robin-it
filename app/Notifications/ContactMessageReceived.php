<?php

namespace App\Notifications;

use Illuminate\Support\Str;

/** Somebody wrote in from the contact page. */
class ContactMessageReceived extends ShopNotification
{
    public function __construct(
        public readonly int $messageId,
        public readonly string $fromName,
        public readonly string $subject,
    ) {}

    public function payload(object $notifiable): array
    {
        return [
            'kind' => 'message.received',
            'title' => 'Message from '.$this->fromName,
            'body' => Str::limit($this->subject, 90),
            'url' => '/admin/messages',
            'icon' => 'message',
        ];
    }
}
