<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\CampaignService;
use App\Support\RichText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Telling every customer about something at once.
 *
 * The shop had been collecting a mailing list for a year with nowhere to send
 * it, and could not text its customers about anything but their own orders.
 */
class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Campaigns', [
            'campaigns' => Campaign::query()
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->latest('id')
                ->paginate(15)
                ->withQueryString(),
            'filters' => ['status' => $request->query('status')],
            'statuses' => Campaign::STATUSES,
            'channels' => Campaign::CHANNELS,
            'audiences' => Campaign::AUDIENCES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $campaign = Campaign::create($this->validated($request) + [
            'status' => Campaign::DRAFT,
            'user_id' => $request->user()->id,
            'created_by_name' => $request->user()->name,
        ]);

        return $this->successResponse($campaign, "\"{$campaign->title}\" saved as a draft.");
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);

        if (! $campaign->isEditable()) {
            return $this->errorResponse(
                'This campaign has already gone out. Copy it into a new one instead — '
                    .'the version on several thousand phones cannot be corrected.',
                422
            );
        }

        $campaign->update($this->validated($request));

        return $this->successResponse($campaign, 'Campaign updated.');
    }

    /**
     * Who it would reach and what the texts would cost, before sending.
     *
     * A blast is the one message where the bill is worth seeing in advance:
     * one em dash pushes the whole thing into unicode and halves what fits in
     * a part, which on five thousand numbers is a doubled invoice.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $estimate = $this->campaigns->estimate(new Campaign($data));

        return $this->successResponse($estimate, 'Estimated.');
    }

    public function send(int $id): JsonResponse
    {
        $campaign = $this->campaigns->send(Campaign::findOrFail($id));

        return $this->successResponse(
            $campaign,
            "Going out to {$campaign->recipient_count} "
                .($campaign->recipient_count === 1 ? 'recipient' : 'recipients').'.'
        );
    }

    /** Who got it, who did not, and why not. */
    public function recipients(Request $request, int $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);

        return $this->paginatedResponse(
            $campaign->recipients()
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->latest('id')
                ->paginate(50)
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);

        if ($campaign->status === Campaign::SENDING) {
            return $this->errorResponse('This campaign is still going out.', 422);
        }

        $campaign->delete();

        return $this->successResponse([], 'Campaign deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:160',
            'subject' => 'nullable|required_if:channel,email,both|string|max:180',
            'body' => 'required|string|max:5000',
            'channel' => 'required|in:'.implode(',', array_keys(Campaign::CHANNELS)),
            'audience' => 'required|in:'.implode(',', array_keys(Campaign::AUDIENCES)),
        ], [
            'subject.required_if' => 'An email needs a subject line.',
        ]);

        // The body is written by staff and rendered as HTML in the email, so
        // it is cleaned on the way in — the same rule the content pages follow.
        $data['body'] = RichText::clean($data['body']);

        return $data;
    }
}
