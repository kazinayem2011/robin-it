<?php

namespace App\Policies;

use App\Models\ContactMessage;
use App\Models\User;

/**
 * Whose enquiry this is.
 *
 * The contact form is open to anyone, so a message need not belong to an
 * account at all — a guest's has no owner and no thread to read. One that does
 * belongs to exactly one customer, and nobody else may read it or write on it.
 */
class ContactMessagePolicy
{
    public function view(User $user, ContactMessage $message): bool
    {
        return $message->user_id !== null && $message->user_id === $user->id;
    }

    /** A thread is answerable by its owner whatever state it is in: writing
     *  again reopens a closed one, which is the point of writing again. */
    public function reply(User $user, ContactMessage $message): bool
    {
        return $this->view($user, $message);
    }
}
