<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who may see and act on an order.
 *
 * These rules were written out by hand at each call site — an `->where('user_id',
 * $user->id)` here, a hand-rolled `mayView()` there. They were right everywhere
 * they appeared, but only because whoever added the endpoint remembered; there
 * was nothing to forget to call. Gathering them here means "is this order
 * theirs?" has one answer.
 */
class OrderPolicy
{
    /**
     * An admin sees everything in the shop.
     */
    public function before(?User $user, string $ability): ?bool
    {
        return $user?->isAdmin() ? true : null;
    }

    public function view(?User $user, Order $order): bool
    {
        return $user !== null && $order->user_id === $user->id;
    }

    /**
     * A customer may call off an order until it has left the building. Once it
     * is shipped, cancellation is a support/returns conversation.
     */
    public function cancel(?User $user, Order $order): bool
    {
        return $this->view($user, $order) && $order->isCancellableByCustomer();
    }

    /**
     * Printing an invoice.
     *
     * A guest checkout is tied to the session that placed it, so the person who
     * just ordered can still print their own receipt without an account.
     */
    public function print(?User $user, Order $order, Request $request): bool
    {
        if ($this->view($user, $order)) {
            return true;
        }

        return $order->user_id === null
            && $order->session_id !== null
            && $order->session_id === $request->session()->getId();
    }
}
