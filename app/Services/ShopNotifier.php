<?php

namespace App\Services;

use App\Models\ContactMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\ContactAnswered;
use App\Notifications\ContactMessageReceived;
use App\Notifications\ContactMessageReplied;
use App\Notifications\OrderPlaced;
use App\Notifications\OrderStatusChanged;
use App\Notifications\OrderUpdated;
use App\Notifications\ProductQuestionAsked;
use App\Notifications\StockRanLow;
use App\Support\Roles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/**
 * Who gets told what.
 *
 * The rule is the same one the admin already uses to decide what a member of
 * staff may see: a storekeeper is told about a shelf running low, not about a
 * customer's message. Sending to "all admins" would have the accountant
 * clearing product questions.
 *
 * Kept out of the controllers because the answer is a property of the shop,
 * not of the screen that happened to trigger it — and because six places
 * deciding independently is six places to change when a role does.
 */
class ShopNotifier
{
    /**
     * Staff whose role covers a given ability.
     *
     * @return Collection<int, User>
     */
    public function staffWith(string $ability): Collection
    {
        $roles = collect(Roles::DEFAULT_ROLES)
            ->filter(fn (array $role) => in_array($ability, $role['abilities'], true))
            ->keys();

        if ($roles->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('role', $roles->all())
            ->when(
                Schema::hasColumn('users', 'is_active'),
                fn ($q) => $q->where('is_active', true)
            )
            ->get();
    }

    public function orderPlaced(Order $order): void
    {
        $this->deliver('order placed', fn () => Notification::send(
            $this->staffWith('orders'),
            new OrderPlaced($order)
        ));
    }

    public function questionAsked(ProductQuestion $question): void
    {
        $this->deliver('question asked', fn () => Notification::send(
            $this->staffWith('support'),
            new ProductQuestionAsked($question)
        ));
    }

    public function contactMessage(int $messageId, string $fromName, string $subject): void
    {
        $this->deliver('contact message', fn () => Notification::send(
            $this->staffWith('support'),
            new ContactMessageReceived($messageId, $fromName, $subject)
        ));
    }

    /** The customer wrote back on a thread the shop had already answered. */
    public function contactReplied(ContactMessage $message): void
    {
        $this->deliver('contact replied', fn () => Notification::send(
            $this->staffWith('support'),
            new ContactMessageReplied($message->id, $message->name, $message->subject)
        ));
    }

    /** And the other way: the shop answered, so the customer's bell rings. */
    public function contactAnswered(ContactMessage $message): void
    {
        $this->deliver('contact answered', fn () => $message->customer?->notify(new ContactAnswered($message)));
    }

    public function stockRanLow(Product $product, ?ProductVariant $variant, int $remaining): void
    {
        $this->deliver('stock ran low', fn () => Notification::send(
            $this->staffWith('stock'),
            new StockRanLow($product, $variant, $remaining)
        ));
    }

    /** The customer's own order. Nobody else is told. */
    public function orderStatusChanged(Order $order, string $status): void
    {
        $this->deliver('order status', fn () => $order->user?->notify(new OrderStatusChanged($order, $status)));
    }

    /**
     * The lines on the customer's own order changed. A guest's order has no
     * account to tell; the email and the text still reach them.
     */
    public function orderUpdated(Order $order): void
    {
        $this->deliver('order updated', fn () => $order->user?->notify(new OrderUpdated($order)));
    }

    /**
     * Send once the change being announced is saved, and never let the
     * sending break the thing that was announced.
     *
     * The bell's push now goes out inside the request (see ShopNotification),
     * so two things that were the queue's problem are this one's. A push from
     * inside a transaction that then rolls back announces something that never
     * happened — afterCommit waits, and runs at once when there is no
     * transaction. And Pusher being down must not turn a placed order into an
     * error page: the database row is written before the push is attempted,
     * so the bell still shows it on the next load.
     */
    private function deliver(string $what, callable $send): void
    {
        DB::afterCommit(function () use ($what, $send) {
            try {
                $send();
            } catch (\Throwable $e) {
                Log::warning("Could not deliver the {$what} notification: {$e->getMessage()}");
            }
        });
    }
}
