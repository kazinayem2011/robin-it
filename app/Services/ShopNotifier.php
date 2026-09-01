<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\ContactMessageReceived;
use App\Notifications\OrderPlaced;
use App\Notifications\OrderStatusChanged;
use App\Notifications\ProductQuestionAsked;
use App\Notifications\StockRanLow;
use App\Support\Roles;
use Illuminate\Support\Collection;
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
        Notification::send($this->staffWith('orders'), new OrderPlaced($order));
    }

    public function questionAsked(ProductQuestion $question): void
    {
        Notification::send($this->staffWith('support'), new ProductQuestionAsked($question));
    }

    public function contactMessage(int $messageId, string $fromName, string $subject): void
    {
        Notification::send(
            $this->staffWith('support'),
            new ContactMessageReceived($messageId, $fromName, $subject)
        );
    }

    public function stockRanLow(Product $product, ?ProductVariant $variant, int $remaining): void
    {
        Notification::send($this->staffWith('stock'), new StockRanLow($product, $variant, $remaining));
    }

    /** The customer's own order. Nobody else is told. */
    public function orderStatusChanged(Order $order, string $status): void
    {
        $customer = $order->user;

        if ($customer) {
            $customer->notify(new OrderStatusChanged($order, $status));
        }
    }
}
