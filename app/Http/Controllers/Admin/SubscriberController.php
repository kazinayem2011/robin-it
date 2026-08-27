<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use App\Services\SubscriptionService;
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
    /** Only these, so a query string cannot order by an arbitrary column. */
    private const SORTABLE = ['email', 'name', 'source', 'subscribed_at', 'unsubscribed_at'];

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request): Response
    {
        $showing = $request->query('status') === 'unsubscribed' ? 'unsubscribed' : 'subscribed';
        [$by, $dir] = $this->sort($request, $showing === 'unsubscribed' ? 'unsubscribed_at' : 'subscribed_at');

        return Inertia::render('Admin/Subscribers', [
            'subscribers' => Subscriber::query()
                ->when($showing === 'unsubscribed',
                    fn ($q) => $q->where('status', Subscriber::UNSUBSCRIBED),
                    fn ($q) => $q->active())
                ->when($request->filled('q'), fn ($q) => $q->where(function ($w) use ($request) {
                    $term = '%'.$request->query('q').'%';
                    $w->where('email', 'like', $term)->orWhere('name', 'like', $term);
                }))
                ->orderBy($by, $dir)
                ->paginate(30)
                ->withQueryString(),
            'filters' => [
                'status' => $showing,
                'q' => $request->query('q', ''),
                'sort' => ['by' => $by, 'dir' => $dir],
            ],
            'counts' => [
                'subscribed' => Subscriber::active()->count(),
                'unsubscribed' => Subscriber::where('status', Subscriber::UNSUBSCRIBED)->count(),
            ],
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sort(Request $request, string $default): array
    {
        $by = $request->query('sort');
        $dir = strtolower((string) $request->query('dir')) === 'asc' ? 'asc' : 'desc';

        return [in_array($by, self::SORTABLE, true) ? $by : $default, $dir];
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
     * Turn emails to this address on or off.
     *
     * Nothing here deletes a subscriber. A deleted row is added back by the
     * next import as though they had never asked to be left alone, and the
     * record of the request is what proves it was honoured — so the address
     * stays and only the switch moves.
     */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $subscriber = Subscriber::findOrFail($id);
        $wantsEmail = $request->boolean('active');

        if ($wantsEmail) {
            // Through the service, so they get a fresh token: a link from the
            // emails they had before must not take them off again.
            $subscriber = $this->subscriptions->subscribe(
                $subscriber->email,
                $subscriber->name,
                $subscriber->source
            );

            return $this->successResponse(
                ['status' => $subscriber->status],
                "{$subscriber->email} will receive email again."
            );
        }

        $subscriber->forceFill([
            'status' => Subscriber::UNSUBSCRIBED,
            'unsubscribed_at' => now(),
        ])->save();

        return $this->successResponse(
            ['status' => $subscriber->status],
            "{$subscriber->email} will not be emailed."
        );
    }
}
