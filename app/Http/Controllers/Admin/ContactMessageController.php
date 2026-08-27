<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContactReplyRequest;
use App\Http\Requests\Admin\ContactStatusRequest;
use App\Models\ContactMessage;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                'replies:id,contact_message_id,author_name,body,emailed,created_at',
                'assignee:id,name',
            ])
            // Asked for an order, honour it; otherwise the one waiting longest.
            ->when($sortBy, fn ($q) => $q->orderBy($sortBy, $dir), fn ($q) => $q->inbox())
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Messages', [
            'messages' => $messages,
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

    public function reply(ContactReplyRequest $request, int $id): JsonResponse
    {
        $message = ContactMessage::findOrFail($id);
        $reply = $this->contact->reply($message, $request->user(), $request->validated()['body']);

        if ($request->boolean('close')) {
            $this->contact->close($message, $request->user());
        }

        // Said plainly rather than hidden: the answer is saved either way, and
        // whoever sent it needs to know if the customer did not get the email.
        $note = $reply->emailed
            ? "Replied to {$message->email}."
            : "Reply saved, but the email could not be sent to {$message->email}. Check the mail settings.";

        return $this->successResponse(
            ['reply' => $reply->only(['id', 'body', 'author_name', 'emailed']), 'emailed' => $reply->emailed],
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
