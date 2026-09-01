<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
