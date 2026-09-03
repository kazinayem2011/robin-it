<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bell.
 *
 * Reads the same rows the broadcast announced, so a notification that arrived
 * while the tab was closed is still there on the next visit — the socket is
 * the nudge, this is the record.
 *
 * Scoped to the signed-in user by the relation itself, so there is no id in
 * the request that could name somebody else's.
 */
class NotificationController extends Controller
{
    private const PAGE_SIZE = 20;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest()
            ->limit(self::PAGE_SIZE)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'read' => $n->read_at !== null,
                'at' => $n->created_at->diffForHumans(),
                ...$n->data,
            ]);

        return $this->successResponse([
            'notifications' => $notifications,
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /** One, when it is clicked. */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->update(['read_at' => now()]);

        return $this->successResponse(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    /** All of them, for somebody catching up after a weekend. */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->successResponse(['unread' => 0]);
    }

    /**
     * The full list, which the bell is not.
     *
     * The bell holds the last twenty and no way to look further back, mark one
     * unread, or throw any away — so anything older than a busy afternoon was
     * gone from view while still sitting in the table. This is the same rows
     * with somewhere to stand.
     */
    public function page(Request $request): Response
    {
        $user = $request->user();

        $status = $request->query('status', 'all');
        $kind = $request->query('kind');
        $search = trim((string) $request->query('search', ''));

        $notifications = $user->notifications()
            ->when($status === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($status === 'read', fn ($q) => $q->whereNotNull('read_at'))
            /*
             * The payload is JSON, and the two drivers this runs on disagree
             * about how to reach into it — so the kind and the words are
             * matched as text against the whole column. Crude, and right for
             * a column this small: a notification is a title, a line of body
             * and a URL, none of which collide with a kind like
             * "order.placed".
             */
            ->when($kind, fn ($q) => $q->where('data', 'like', '%"kind":"'.$kind.'"%'))
            ->when($search !== '', fn ($q) => $q->where('data', 'like', '%'.$search.'%'))
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn ($n) => [
                'id' => $n->id,
                'read' => $n->read_at !== null,
                'at' => $n->created_at->diffForHumans(),
                'on' => $n->created_at->format('d M Y, g:i A'),
                ...$n->data,
            ]);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'filters' => [
                'status' => $status,
                'kind' => $kind,
                'search' => $search,
            ],
            /*
             * Only the kinds this person has actually received. A filter
             * offering "Low stock" to a customer who will never get one is a
             * dead end dressed as a choice.
             */
            'kinds' => $user->notifications()
                ->pluck('data')
                ->map(fn ($data) => is_array($data) ? ($data['kind'] ?? null) : null)
                ->filter()
                ->unique()
                ->sort()
                ->values(),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /** Back to unread, for something dealt with too quickly. */
    public function markUnread(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->update(['read_at' => null]);

        return $this->successResponse(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    /** One, gone. Scoped by the relation, so no id can name somebody else's. */
    public function destroy(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->delete();

        return $this->successResponse(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    /**
     * Everything already read.
     *
     * Deliberately not everything: clearing the unread ones would throw away
     * the notifications nobody has looked at, which is the opposite of what
     * somebody tidying up means.
     */
    public function clearRead(Request $request): JsonResponse
    {
        $deleted = $request->user()->notifications()->whereNotNull('read_at')->delete();

        return $this->successResponse(
            ['deleted' => $deleted, 'unread' => $request->user()->unreadNotifications()->count()],
            $deleted === 1 ? '1 notification cleared.' : "{$deleted} notifications cleared."
        );
    }
}
