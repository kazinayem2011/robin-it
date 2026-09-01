<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Something the shop wants somebody told about, now.
 *
 * Every notification here goes two places at once. The database row is the
 * record: it survives a closed tab, a reload and a night's sleep, and it is
 * what the bell counts. The broadcast is the nudge: it arrives over the socket
 * within the second, for whoever happens to be looking.
 *
 * Both, deliberately. Broadcast alone loses anything that happened while
 * nobody was watching, which for a shop is most of the day. Database alone
 * means an order sits unseen until somebody reloads.
 *
 * If Pusher is not configured the broadcast simply goes nowhere — the row is
 * still written, the bell still counts it, and the shop still works. That is
 * the difference between the feature being unavailable and the feature being
 * broken.
 */
abstract class ShopNotification extends Notification
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * What the bell shows. Kept flat and already-formatted: the browser should
     * not have to know how to phrase an order total or which icon a refund
     * gets.
     *
     * @return array<string, mixed>
     */
    abstract public function payload(object $notifiable): array;

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->payload($notifiable);
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload($notifiable));
    }

    /*
     * broadcastType() is left alone on purpose.
     *
     * It does not name the event — broadcastAs() does that, and for a
     * notification it is always BroadcastNotificationCreated, which is what
     * Echo's .notification() helper subscribes to. broadcastType() only sets
     * the `type` field inside the payload, and the class name Laravel puts
     * there by default says more than a fixed string of ours would.
     *
     * The bell reads `kind` from the payload below rather than either, so it
     * does not depend on this at all.
     */
}
