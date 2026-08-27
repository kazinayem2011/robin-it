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
    public function record(array $data, ?string $ip = null): ContactMessage
    {
        return ContactMessage::create([
            'name' => $data['name'],
            'email' => mb_strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'status' => ContactMessage::STATUS_NEW,
            'ip_address' => $ip,
        ]);
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
