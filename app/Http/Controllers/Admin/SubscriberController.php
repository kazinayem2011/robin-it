<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who has asked to hear from the shop.
 */
class SubscriberController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Subscribers', [
            'subscribers' => Subscriber::query()
                ->when($request->query('status') === 'unsubscribed',
                    fn ($q) => $q->where('status', Subscriber::UNSUBSCRIBED),
                    fn ($q) => $q->active())
                ->latest('subscribed_at')
                ->paginate(30)
                ->withQueryString(),
            'filters' => ['status' => $request->query('status', 'subscribed')],
            'counts' => [
                'subscribed' => Subscriber::active()->count(),
                'unsubscribed' => Subscriber::where('status', Subscriber::UNSUBSCRIBED)->count(),
            ],
        ]);
    }

    /**
     * The list, as a file the shop can put into whatever sends its email.
     *
     * Only the ones who currently want it: exporting the people who left is
     * how they end up mailed again.
     */
    public function export(): StreamedResponse
    {
        $filename = 'subscribers-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'name', 'subscribed_at', 'source']);

            Subscriber::active()->orderBy('email')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->email,
                        $row->name,
                        $row->subscribed_at?->toDateString(),
                        $row->source,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Take someone off by hand, when they have asked another way.
     *
     * Marked as unsubscribed rather than deleted — a deleted row is added back
     * by the next import, and the record is what proves the request was
     * honoured.
     */
    public function destroy(int $id): JsonResponse
    {
        $subscriber = Subscriber::findOrFail($id);

        $subscriber->forceFill([
            'status' => Subscriber::UNSUBSCRIBED,
            'unsubscribed_at' => now(),
        ])->save();

        return $this->successResponse([], "{$subscriber->email} will not be emailed again.");
    }
}
