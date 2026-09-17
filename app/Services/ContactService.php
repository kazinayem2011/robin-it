<?php

namespace App\Services;

use App\Mail\ContactReplyMail;
use App\Models\ContactMessage;
use App\Models\ContactReply;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The contact inbox: what came in, and what was said back.
 */
class ContactService
{
    public function __construct(protected ShopNotifier $notifier) {}

    /**
     * @param  User|null  $customer  whoever was signed in, so the enquiry shows
     *                               in their own messages and they can write
     *                               back there. The form stays open to guests,
     *                               whose enquiry belongs to nobody.
     */
    public function record(array $data, ?string $ip = null, ?User $customer = null): ContactMessage
    {
        $message = ContactMessage::create([
            'user_id' => $customer?->id,
            'name' => $data['name'],
            'email' => mb_strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'status' => ContactMessage::STATUS_NEW,
            'ip_address' => $ip,
        ]);

        $this->notifier->contactMessage($message->id, $message->name, $message->subject);

        return $message;
    }

    /**
     * Answer a message.
     *
     * The reply is saved before it is sent. A mail server that is down should
     * not lose what somebody wrote, and a reply nobody can find is worse than
     * one that has not gone out yet — the row records whether it was emailed,
     * so an unsent one can be seen and sent again.
     */
    public function reply(ContactMessage $message, User $staff, string $body): ContactReply
    {
        $reply = DB::transaction(function () use ($message, $staff, $body) {
            $reply = ContactReply::create([
                'contact_message_id' => $message->id,
                'user_id' => $staff->id,
                'author_name' => $staff->name,
                'from_customer' => false,
                'body' => $body,
                'emailed' => false,
            ]);

            // Answering takes it off the "new" pile and puts a name to it, but
            // does not close it — the customer may write back.
            if ($message->status === ContactMessage::STATUS_NEW) {
                $message->status = ContactMessage::STATUS_OPEN;
            }

            $message->assigned_to = $message->assigned_to ?: $staff->id;
            $message->save();

            return $reply;
        });

        try {
            Mail::to($message->email)->send(new ContactReplyMail($message, $reply));
            $reply->forceFill(['emailed' => true])->save();
        } catch (\Throwable $e) {
            // Kept, not thrown: the answer is recorded either way, and the
            // person answering should be told rather than shown a 500.
            Log::warning('Contact reply saved but not emailed', [
                'contact_message_id' => $message->id,
                'reply_id' => $reply->id,
                'error' => $e->getMessage(),
            ]);
        }

        // The bell as well, for a customer with an account: the thread is
        // theirs to read and to answer, and an inbox is not always read.
        $this->notifier->contactAnswered($message);

        return $reply->fresh();
    }

    /**
     * The customer writing back on their own thread.
     *
     * An answer that did not settle it used to have nowhere to go: the reply
     * arrived by email, and email replies land in a mailbox rather than in the
     * inbox screen, so the shop could not see them beside what it had said.
     *
     * A closed thread reopens. Somebody writing again is the plainest possible
     * statement that it was not finished.
     */
    public function replyFromCustomer(ContactMessage $message, User $customer, string $body): ContactReply
    {
        $reply = DB::transaction(function () use ($message, $customer, $body) {
            $reply = ContactReply::create([
                'contact_message_id' => $message->id,
                'user_id' => $customer->id,
                'author_name' => $customer->name,
                'from_customer' => true,
                'body' => $body,
                // Nothing is emailed to the shop: it reads the thread.
                'emailed' => false,
            ]);

            $message->forceFill([
                'status' => ContactMessage::STATUS_OPEN,
                'closed_at' => null,
                'closed_by' => null,
            ])->save();

            return $reply;
        });

        $this->notifier->contactReplied($message);

        return $reply->fresh();
    }

    public function close(ContactMessage $message, User $staff): ContactMessage
    {
        $message->forceFill([
            'status' => ContactMessage::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $staff->id,
        ])->save();

        return $message;
    }

    /**
     * Put a closed message back in the inbox.
     *
     * It goes back to "in progress" rather than "new": it has been seen, and
     * "new" is the pile of things nobody has looked at yet.
     */
    public function reopen(ContactMessage $message): ContactMessage
    {
        $message->forceFill([
            'status' => ContactMessage::STATUS_OPEN,
            'closed_at' => null,
            'closed_by' => null,
        ])->save();

        return $message;
    }
}
