<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContactReplyRequest;
use App\Http\Requests\Admin\ContactStatusRequest;
use App\Models\ContactMessage;
use App\Models\User;
use App\Services\ContactService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The contact inbox.
 */
class ContactMessageController extends Controller
{
    public function __construct(private readonly ContactService $contact) {}

    /** Only these, so a query string cannot order by an arbitrary column. */
    private const SORTABLE = ['created_at', 'name', 'subject', 'status'];

    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $sortBy = in_array($request->query('sort'), self::SORTABLE, true)
            ? $request->query('sort')
            : null;
        $dir = strtolower((string) $request->query('dir')) === 'asc' ? 'asc' : 'desc';

        $messages = ContactMessage::query()
            ->when(in_array($status, ContactMessage::STATUSES, true),
                fn ($q) => $q->where('status', $status))
            ->when($request->filled('q'), fn ($q) => $q->where(function ($w) use ($request) {
                $term = '%'.$request->query('q').'%';
                $w->where('subject', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('message', 'like', $term);
            }))
            ->with([
                // from_customer: a thread has two voices now, and the screen
                // marks which is which.
                'replies:id,contact_message_id,author_name,from_customer,body,emailed,created_at',
                'assignee:id,name',
                'customer:id,name',
            ])
            // Asked for an order, honour it; otherwise the one waiting longest.
            ->when($sortBy, fn ($q) => $q->orderBy($sortBy, $dir), fn ($q) => $q->inbox())
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Messages', [
            'messages' => $this->withSenders($messages),
            'filters' => [
                'status' => $status,
                'q' => $request->query('q', ''),
                'sort' => ['by' => $sortBy, 'dir' => $dir],
            ],
            // So the tabs can show what is waiting without loading each list.
            'counts' => [
                'new' => ContactMessage::where('status', ContactMessage::STATUS_NEW)->count(),
                'open' => ContactMessage::where('status', ContactMessage::STATUS_OPEN)->count(),
                'closed' => ContactMessage::where('status', ContactMessage::STATUS_CLOSED)->count(),
            ],
        ]);
    }

    /**
     * Who the shop is actually talking to.
     *
     * The form is open to anyone, and the address on a message is simply what
     * was typed: somebody not signed in can put a customer's address in the
     * box, and the screen showed nothing to say so. Staff would read the
     * address, take it for that customer, and answer with what is on their
     * account — to an inbox that is theirs, but at the word of somebody who
     * never proved they are them.
     *
     * Three things this can be, and the screen now says which:
     *   signed in     the account is known, and the thread is theirs
     *   guest         nobody the shop knows; answer the address, nothing more
     *   guest, but the address belongs to an account — the one to be careful
     *                 with, because it looks exactly like the first
     *
     * One query for the page, not one per row.
     *
     * @param  LengthAwarePaginator<int, ContactMessage>  $messages
     */
    private function withSenders(LengthAwarePaginator $messages): LengthAwarePaginator
    {
        $unclaimed = collect($messages->items())
            ->filter(fn (ContactMessage $message) => $message->user_id === null)
            ->pluck('email')
            ->map(fn ($email) => mb_strtolower((string) $email))
            ->unique()
            ->all();

        $withAccounts = $unclaimed === []
            ? collect()
            : User::whereIn(DB::raw('LOWER(email)'), $unclaimed)
                ->pluck('email')
                ->map(fn ($email) => mb_strtolower((string) $email))
                ->flip();

        $messages->getCollection()->each(function (ContactMessage $message) use ($withAccounts) {
            $message->setAttribute('sender', [
                'signed_in' => $message->user_id !== null,
                'account_name' => $message->customer?->name,
                'address_has_account' => $message->user_id === null
                    && $withAccounts->has(mb_strtolower((string) $message->email)),
            ]);
        });

        return $messages;
    }

    public function reply(ContactReplyRequest $request, int $id): JsonResponse
    {
        $message = ContactMessage::findOrFail($id);
        $reply = $this->contact->reply($message, $request->user(), $request->validated()['body']);

        if ($request->boolean('close')) {
            $this->contact->close($message, $request->user());
        }

        // Said plainly rather than hidden: the answer is saved either way, and
        // whoever sent it needs to know if the customer did not get the email.
        $note = match (true) {
            $reply->emailed => "Replied to {$message->email}.",
            // Nothing to email is not a failure: a customer reads it in their
            // own messages, and a guest who left only a number is texted.
            (bool) $reply->texted => "Replied by text to {$message->phone}.",
            blank($message->email) && $message->user_id !== null => 'Replied. They will see it in their messages.',
            blank($message->email) && filled($message->phone) => app(SmsService::class)->sends('contact_reply')
                ? "Saved, but the text could not be sent — call {$message->phone}."
                : "Saved. This enquiry left no email address — call {$message->phone}.",
            blank($message->email) => 'Saved. This enquiry left no way to answer it.',
            default => "Reply saved, but the email could not be sent to {$message->email}. Check the mail settings.",
        };

        return $this->successResponse(
            [
                'reply' => $reply->only(['id', 'body', 'author_name', 'emailed']),
                'emailed' => $reply->emailed,
                'texted' => (bool) $reply->texted,
            ],
            $note
        );
    }

    public function updateStatus(ContactStatusRequest $request, int $id): JsonResponse
    {
        $message = ContactMessage::findOrFail($id);
        $status = $request->validated()['status'];

        $message = $status === ContactMessage::STATUS_CLOSED
            ? $this->contact->close($message, $request->user())
            : $this->contact->reopen($message);

        return $this->successResponse(
            $message->only(['id', 'status']),
            $status === ContactMessage::STATUS_CLOSED
                ? 'Marked as closed.'
                : 'Back in the inbox.'
        );
    }
}
