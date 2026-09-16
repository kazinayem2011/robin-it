<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MessageTemplateRequest;
use App\Http\Requests\Admin\TemplateTestRequest;
use App\Mail\TemplatePreviewMail;
use App\Models\EmailTemplate;
use App\Models\SiteSetting;
use App\Models\SmsTemplate;
use App\Services\SmsService;
use App\Support\MailSettings;
use App\Support\MessageTemplate;
use App\Support\TemplateSamples;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the shop says, and the two ways to check it before a customer reads it.
 *
 * The wording used to live in seven Blade files and nine strings in code, so
 * changing a sentence meant a developer and a deploy — which in practice meant
 * the wording never changed. It is rows now, and this screen edits them.
 *
 * Preview and test are not decoration. An email is written in a browser and
 * read in Outlook, and a text message is written in a box that shows no cost
 * and sent through a gateway that charges by the part. Both of those are
 * invisible at the moment of writing, which is exactly when they can still be
 * fixed.
 */
class MessageTemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/MessageTemplates', [
            'emailTemplates' => EmailTemplate::orderBy('group')->orderBy('name')->get(),
            'smsTemplates' => SmsTemplate::orderBy('group')->orderBy('name')->get()
                ->map(fn (SmsTemplate $t) => $t->append('parts')),

            /*
             * What the preview fills in, sent along so the editor can show the
             * cost of an SMS as it is typed rather than only when asked.
             */
            'samples' => TemplateSamples::all(),
            'smsEnabled' => (bool) SiteSetting::get('sms_enabled'),
        ]);
    }

    public function update(MessageTemplateRequest $request, string $type, int $id): JsonResponse
    {
        $template = $this->find($type, $id);
        $data = $request->validated();

        /*
         * Refused when a placeholder the template declares has gone. In a rich
         * text editor `{customer_name}` is just text: click into the middle of
         * it, type, and it silently becomes something that will never be
         * filled in. Nothing objects, the template saves, and the shop finds
         * out when a customer receives "Hi {customer_nam e},".
         */
        /*
         * Subject and body together. A declared placeholder may legitimately
         * live in either — `{shop_name}` is in the subject of most of these —
         * and checking only the body refused every save of a template whose
         * variables it names.
         */
        $missing = MessageTemplate::missing(
            $template->variables ?? [],
            ($data['subject'] ?? $template->subject).' '.$data['body'],
        );

        if ($missing) {
            return $this->errorResponse(
                MessageTemplate::complaint($missing),
                422,
                ApiCode::VALIDATION_ERROR,
                ['missing' => $missing],
            );
        }

        /*
         * And refused when a text message has no Bengali left in it.
         *
         * The gateway's rule, not ours: every SMS must be sent in Bengali,
         * Banglish is not allowed, and Bengali mixed with English is fine —
         * English alone is not. What is at stake is the sending account
         * itself, and this screen is precisely where somebody rewording a
         * sentence would break it without ever seeing the notice.
         */
        if ($type === 'sms' && ! SmsService::hasBengali($data['body'])) {
            return $this->errorResponse(
                'The SMS gateway only accepts messages with Bengali in them — '
                    .'mixing Bengali and English is fine, English on its own is not, '
                    .'and Banglish (Amar / Ami / Tumi) is refused outright.',
                422,
                ApiCode::VALIDATION_ERROR,
            );
        }

        $template->fill($data)->save();

        return $this->successResponse(
            $this->present($type, $template->fresh()),
            'Template saved.',
        );
    }

    /** The message as it will arrive, filled with plausible values. */
    public function preview(string $type, int $id): JsonResponse
    {
        $template = $this->find($type, $id);
        $values = TemplateSamples::all();

        if ($type === 'sms') {
            $body = MessageTemplate::fill((string) $template->body, $values);

            return $this->successResponse([
                'body' => $body,
                'characters' => mb_strlen($body),
                /*
                 * Counted on the filled message, not the template. A written
                 * `{order_number}` is fourteen characters and an order number
                 * is eight, so counting the template overstates every one.
                 */
                'parts' => SmsService::parts($body),
                'unicode' => SmsService::parts($body) > 0 && mb_strlen($body) > 160,
            ], 'Preview built.');
        }

        return $this->successResponse([
            'subject' => MessageTemplate::fill((string) $template->subject, $values),
            /*
             * Rendered inside the real layout. The words are what the shop
             * edits; the table markup and inline styles around them are what
             * Outlook needs, and a preview without them would be a preview of
             * something nobody receives.
             */
            'html' => view('emails.templated', [
                'title' => MessageTemplate::fill((string) $template->subject, $values),
                'preheader' => null,
                'bodyHtml' => MessageTemplate::fill((string) $template->body, $values),
            ])->render(),
        ], 'Preview built.');
    }

    /**
     * Send one, for real, to an address or a number the admin names.
     *
     * Synchronously rather than through the queue, and for the same reason the
     * SMTP test is: an admin checking their wording needs the gateway's actual
     * refusal, not a job that failed quietly an hour later.
     */
    public function test(TemplateTestRequest $request, string $type, int $id): JsonResponse
    {
        $template = $this->find($type, $id);
        $values = TemplateSamples::all();
        $to = $request->validated()['to'];

        if ($type === 'sms') {
            $body = MessageTemplate::fill((string) $template->body, $values);
            $sent = app(SmsService::class)->send($to, $body);

            return $sent
                ? $this->successResponse(
                    ['parts' => SmsService::parts($body)],
                    "Test message sent to {$to}. It costs ".SmsService::parts($body).' part(s).',
                )
                : $this->errorResponse(
                    'The gateway did not accept it. Check the SMS settings, and that it is switched on.',
                    422,
                    ApiCode::GENERIC,
                );
        }

        MailSettings::apply();

        try {
            Mail::mailer(config('mail.default'))->to($to)->sendNow(
                new TemplatePreviewMail(
                    MessageTemplate::fill((string) $template->subject, $values),
                    MessageTemplate::fill((string) $template->body, $values),
                )
            );
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Could not send: '.$e->getMessage(),
                422,
                ApiCode::GENERIC,
            );
        }

        return $this->successResponse([], "Test email sent to {$to}. Check the inbox to confirm.");
    }

    private function find(string $type, int $id): EmailTemplate|SmsTemplate
    {
        abort_unless(in_array($type, ['email', 'sms'], true), 404);

        return $type === 'email'
            ? EmailTemplate::findOrFail($id)
            : SmsTemplate::findOrFail($id);
    }

    private function present(string $type, EmailTemplate|SmsTemplate $template): array
    {
        return $type === 'sms'
            ? $template->append('parts')->toArray()
            : $template->toArray();
    }
}
